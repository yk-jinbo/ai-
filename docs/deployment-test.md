# 测试环境自动部署

本文对应现有测试站点 `ai-suanli-test.118.190.203.44.sslip.io`，由 GitHub 的 `test` 分支推送触发。部署脚本和 Webhook 接收器放在仓库中维护，安装到服务器后独立运行。

## 故障原因与处理

服务器原生 Git 访问 GitHub 时出现过 `GnuTLS recv error (-110)` 和连接超时，容器 Git 也会间歇超时。探测确认部分直连的 GitHub 官方地址可用，拉取现在依次尝试一个已验证的 GitHub 官方地址、容器 Git 的普通域名路由、宿主机普通域名路由和第二个 GitHub 官方地址，再循环重试。通过 Git 的 `http.curloptResolve` 固定官方 HTTPS 主机名对应的连接地址，保留 HTTP/1.1、TLS 证书校验及 `github.com` 主机名；容器使用宿主机网络，不临时拉取镜像，不依赖第三方代理。

每次拉取默认最多尝试 4 次，每次限时 40 秒，失败后分别等待 2、4、6 秒；超时后清理对应的临时容器。持续网络故障仍会导致部署失败，失败阶段及退出码会写入日志。

部署以 `ecs-user` 执行。人工直接用 root 拉取会产生自动部署无法写入的 `.git/objects` 子目录；本次已修复受影响文件的属主。人工更新也应通过下面的同一部署服务触发，避免再次改变 Git 文件权限。

2026-11-04 的两次推送都正常触发了 Webhook，但均在拉取阶段耗尽重试。网络随后恢复时没有新事件再次触发，因此旧镜像一直运行。新增 `ai-test-reconcile.timer`：开机后两分钟开始检查，并在上次检查结束两分钟后再次通过同一部署服务同步最新 `test` 分支。网络恢复后自动重试，漏投 Webhook 也能补上；已成功部署的版本只拉取检查，不重复构建或重启容器。

原 Webhook 收到提交后就永久记录“已接收”，导致部署失败后重新投递也无法重试。现在只依赖 systemd 合并同一提交正在执行的任务，并由部署成功标记跳过已完成版本；失败提交可以重新投递。

## 文件与运行路径

| 仓库文件 | 服务器安装位置 |
| --- | --- |
| `scripts/deployment/deploy-test.sh` | `/opt/1panel/ai-test-webhook/deploy.sh` |
| `scripts/deployment/webhook_server.py` | `/opt/1panel/ai-test-webhook/server.py` |
| `scripts/deployment/ai-test-deploy@.service` | `/etc/systemd/system/ai-test-deploy@.service` |
| `scripts/deployment/ai-test-reconcile.timer` | `/etc/systemd/system/ai-test-reconcile.timer` |

现有服务器配置继续使用：

- Webhook 服务：`ai-test-webhook.service`，入口 `/hooks/github-test`，端口 `19001`。
- 签名配置：`/etc/ai-test-webhook.env` 的 `WEBHOOK_SECRET`，不放入仓库或日志。
- 权限入口：`/usr/local/sbin/ai-test-trigger`，由已有 sudoers 规则允许接收器触发指定 SHA 的 systemd 单元。
- 应用目录：`/opt/1panel/www/km_user_backend/ai-suanli-yidongduan-test`。
- 构建配置：应用目录中的 `docker-compose.test.yml`，这是现有服务器配置，不是仓库的 `docker-compose.image.yml`。

## 执行流程

1. 接收器校验 HMAC-SHA256 签名、仓库 `yk-jinbo/ai-`、分支 `refs/heads/test` 和提交 SHA，再异步触发部署单元。
2. 部署脚本通过 `flock` 串行执行，确认工作区为 `test` 分支、远程地址正确，且没有已跟踪文件的本地修改。现有服务器对 `.dockerignore` 的修改为唯一例外。
3. 拉取并快进到远程 `test` 的最新版本。Webhook 的 SHA 用于关联请求，实际部署 SHA 以拉取结果为准，因此延迟投递旧事件不会回退版本。
4. 目标版本与 `deployed.sha` 相同时跳过构建；否则构建镜像，再替换 `app` 容器。
5. 确认容器运行，执行 `php scripts/check-runtime-assets.php`，并重试检查本地 `/api/health` 的 HTTP 状态和 `code=0`。
6. 所有检查通过后才更新 `deployed.sha`。失败保留上次成功标记，允许同一提交重试。
7. 定时器重复调用 `ai-test-deploy@manual.service` 补偿网络失败和漏投事件，仍经过工作区保护、串行锁及全部验证。持续网络故障时会继续留下失败日志，恢复后部署最新分支。

HTTP `202 accepted` 只表示部署已触发，最终结果以状态文件和部署日志为准。构建失败不会替换运行容器；替换后的验证失败会报告失败，但不会自动回滚容器。

## 查状态与重试

在服务器执行：

```bash
cat /var/lib/ai-test-deploy/status.json
cat /var/lib/ai-test-deploy/deployed.sha
tail -n 100 /var/lib/ai-test-deploy/deploy.log
journalctl -u ai-test-webhook.service -n 30 --no-pager
systemctl list-timers ai-test-reconcile.timer --no-pager
```

状态文件包含 `status`、`stage`、`requested_sha`、`commit`、`exit_code`、`updated_at`。日志每条阶段消息也带请求 SHA、实际部署 SHA 和时间。常见失败阶段为 `preflight`、`fetch`、`merge`、`build`、`replace`、`verify`。

失败后可在 GitHub Webhook 的投递记录中选择 **Redeliver**。也可在服务器手动触发一次最新分支部署：

```bash
sudo systemctl start --no-block ai-test-deploy@manual.service
```

人工触发依然经过同一部署脚本和检查；成功版本会跳过重复构建。不要清空状态目录或修改 `deployed.sha` 来掩盖失败。

## 更新运维脚本

以下命令在既有服务器上以 root 执行，工作目录为已经包含本次脚本的仓库。不会创建新站点、修改签名密钥或重建应用容器。

```bash
bash -n scripts/deployment/deploy-test.sh
python3 -c "import ast,pathlib; ast.parse(pathlib.Path('scripts/deployment/webhook_server.py').read_text())"
systemd-analyze verify scripts/deployment/ai-test-deploy@.service scripts/deployment/ai-test-reconcile.timer

backup_dir="/opt/1panel/ai-test-webhook/backups/$(date +%Y%m%d-%H%M%S)"
install -d -m 0700 "$backup_dir"
cp -p /opt/1panel/ai-test-webhook/deploy.sh "$backup_dir/deploy.sh"
cp -p /opt/1panel/ai-test-webhook/server.py "$backup_dir/server.py"
cp -p /etc/systemd/system/ai-test-deploy@.service "$backup_dir/ai-test-deploy@.service"
if [ -f /etc/systemd/system/ai-test-reconcile.timer ]; then
    cp -p /etc/systemd/system/ai-test-reconcile.timer "$backup_dir/ai-test-reconcile.timer"
fi

install -m 0755 scripts/deployment/deploy-test.sh /opt/1panel/ai-test-webhook/deploy.sh.new
mv /opt/1panel/ai-test-webhook/deploy.sh.new /opt/1panel/ai-test-webhook/deploy.sh
install -m 0755 scripts/deployment/webhook_server.py /opt/1panel/ai-test-webhook/server.py.new
mv /opt/1panel/ai-test-webhook/server.py.new /opt/1panel/ai-test-webhook/server.py
install -m 0644 scripts/deployment/ai-test-deploy@.service /etc/systemd/system/ai-test-deploy@.service
install -m 0644 scripts/deployment/ai-test-reconcile.timer /etc/systemd/system/ai-test-reconcile.timer
systemctl daemon-reload
systemctl restart ai-test-webhook.service
systemctl enable --now ai-test-reconcile.timer
systemctl is-active ai-test-webhook.service
systemctl is-active ai-test-reconcile.timer
```

若需回退运维脚本，用对应备份目录的文件恢复上表中的安装位置，再执行 `systemctl daemon-reload` 和 `systemctl restart ai-test-webhook.service`。首次安装定时器后的回退先执行 `systemctl disable --now ai-test-reconcile.timer`；已有定时器则恢复备份后重新启用。这只回退部署工具，不回退应用版本。

脚本支持 `AI_TEST_FETCH_ATTEMPTS`、`AI_TEST_FETCH_TIMEOUT`、`AI_TEST_RETRY_DELAY`；路径和镜像配置见脚本顶部的 `AI_TEST_*` 默认值。运维调整使用 `ai-test-deploy@.service` 的 systemd drop-in 中的 `Environment=`，不写入 Laravel 的 `.env`。部署任务总时限为 10 分钟，调大拉取重试配置时需一并评估。

## 回归测试

在 Linux 环境执行，使用标准库及命令替身，不访问真实 Docker、GitHub 或数据库：

```bash
bash -n scripts/deployment/deploy-test.sh
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s tests/Deployment -p 'test_*.py' -v
```

覆盖签名校验、失败后重新投递、重复提交、GitHub 官方 HTTPS 地址固定及 TLS 校验、容器与宿主机 Git 重试、超时清理、定期重试在网络恢复后完成部署且随后跳过重复构建、构建失败、健康检查失败和工作区保护。Windows 下部署脚本测试会跳过，完整验证需要 Linux。
