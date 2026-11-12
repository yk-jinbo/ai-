# 任务跟踪：GitHub Issues

本项目的任务与规格记录在 `yk-jinbo/ai-` 的 GitHub Issues，地址为 https://github.com/yk-jinbo/ai-/issues 。使用 GitHub CLI（`gh`）操作。

## 仓库范围与运行环境

- 从项目根目录运行命令；目标仓库固定为 `yk-jinbo/ai-`，命令显式携带 `--repo yk-jinbo/ai-`。
- 每个会话首次写入前，运行 `gh repo view yk-jinbo/ai- --json nameWithOwner` 核实目标。
- 只能向本项目仓库发布任务，不能向技能来源仓库 `mattpocock/skills` 发布。
- `gh` 需要已登录且具有该仓库访问权限。不可访问时明确说明原因，不静默切换到其他仓库或任务平台。

## 常用操作

- **创建任务**：`gh issue create --repo yk-jinbo/ai- --title "任务标题" --body-file <正文文件路径>`。多行正文先写入 UTF-8 临时文件，以 `--body-file` 传入并保留真实换行；正文和标题使用中文。
- **读取任务**：`gh issue view <编号> --repo yk-jinbo/ai- --json number,title,body,labels,comments`。
- **列出任务**：`gh issue list --repo yk-jinbo/ai- --state open --json number,title,body,labels,comments`，按需要增加 `--label`、`--state` 和分页参数。
- **发表评论**：`gh issue comment <编号> --repo yk-jinbo/ai- --body-file <正文文件路径>`。
- **添加或移除标签**：`gh issue edit <编号> --repo yk-jinbo/ai- --add-label "标签"` 或 `--remove-label "标签"`。
- **关闭任务**：先通过 `gh issue comment` 记录处理结果，再运行 `gh issue close <编号> --repo yk-jinbo/ai-`。不要依赖已关闭任务的 `close --comment`，以免说明未被保存。

## 父子任务与阻塞依赖

- **父子任务**：支持时使用 `gh issue create --parent <父任务编号> ...`，或 `gh issue edit <父任务编号> --add-sub-issue <子任务编号>`，并显式指定本项目仓库。
- **旧版本兼容**：可通过 `gh api --method POST repos/yk-jinbo/ai-/issues/<父任务编号>/sub_issues -F sub_issue_id=<子任务数据库ID>` 建立父子关系；API 参数中的数据库 ID 不等于任务编号。
- **降级约定**：如果仓库不支持子任务，则在父任务正文维护子任务清单，并在子任务正文顶部写 `Part of #<父任务编号>`。
- **阻塞关系**：优先使用 GitHub 原生依赖，例如 `gh issue edit <被阻塞任务编号> --repo yk-jinbo/ai- --add-blocked-by <阻塞任务编号>`。
- **依赖 API**：旧版本可使用 `gh api --method POST repos/yk-jinbo/ai-/issues/<被阻塞任务编号>/dependencies/blocked_by -F issue_id=<阻塞任务数据库ID>`。数据库 ID 通过 `gh api repos/yk-jinbo/ai-/issues/<任务编号> --jq .id` 获取，不使用任务编号或 `node_id` 替代。
- **依赖降级**：无法使用原生依赖时，在正文顶部写 `Blocked by: #<编号>, #<编号>`。全部阻塞任务关闭后才允许启动。
- **开放阻塞数量**：使用 `gh api repos/yk-jinbo/ai-/issues/<编号> --jq .issue_dependencies_summary.blocked_by`。若通过 `gh issue view --json blockedBy` 读取，必须过滤已关闭的阻塞任务。

## Pull requests as a triage surface

**PRs as a request surface: no.**

## 技能指令的含义

- 技能要求“发布到任务跟踪器”时：在 `yk-jinbo/ai-` 创建 GitHub Issue。
- 技能要求“获取相关任务”时：读取用户指定的 GitHub Issue 及其必要评论、标签和依赖。
- GitHub Issue 与 PR 共用编号空间；编号存在歧义时检查对象类型，按对应的 `gh issue` 或 `gh pr` 命令读取。

## Wayfinding 操作

供以后安装的 `wayfinder` 技能读取：一个地图 Issue 对应多个子任务 Issue。

- **地图**：使用 `wayfinder:map` 标签，正文保留 Notes、Decisions-so-far、Fog 三部分。
- **子任务**：作为地图的子任务关联，使用 `wayfinder:<类型>` 标签，类型为 `research`、`prototype`、`grilling` 或 `task`。不支持原生子任务时使用父任务清单和 `Part of #<编号>`。
- **阻塞**：遵循上面的原生依赖和降级规则。
- **可执行任务**：按地图顺序查找开放、无开放阻塞且无人认领的子任务，取第一个。
- **认领**：开始工作前执行 `gh issue edit <编号> --repo yk-jinbo/ai- --add-assignee @me`。
- **解决**：发表评论记录答案、关闭子任务，然后在地图的 Decisions-so-far 追加结论和链接。
- 创建任务所需标签不存在时，先依据当前任务授权补齐标签，不能假定 GitHub 会自动创建。
