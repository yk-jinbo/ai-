param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $ComposerArguments
)

foreach ($proxyName in @('HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'GIT_HTTP_PROXY', 'GIT_HTTPS_PROXY')) {
    Remove-Item "Env:$proxyName" -ErrorAction SilentlyContinue
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$env:COMPOSER_HOME = Join-Path $projectRoot '.composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.composer-cache'

& composer @ComposerArguments
exit $LASTEXITCODE