<#
.SYNOPSIS
Windows equivalent of verify-build-sources.mjs without its bash subprocess.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot/common.ps1"
$root = Get-RepositoryRoot
$suffix = [Guid]::NewGuid().ToString('N')
$fixture = Join-Path $root ('verify-build-fixture-' + $suffix)
$view = Join-Path $root ('storage/framework/views/verify-build-fixture-' + $suffix + '.php')
$pnpm = Get-Pnpm
function Get-AssetDigest {
    $directory = Join-Path $root 'public/build'
    $entries = foreach ($file in Get-ChildItem -LiteralPath $directory -Recurse -File | Sort-Object FullName) {
        $file.FullName.Substring($directory.Length) + ':' + (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
    }
    return $entries -join '|'
}
try {
    Invoke-Checked $pnpm @('build')
    $baseline = Get-AssetDigest
    [void][IO.Directory]::CreateDirectory($fixture)
    $utility = '<div class="top-[' + (Get-Random -Minimum 100000 -Maximum 999999) + 'px]">owned scan fixture</div>'
    [IO.File]::WriteAllText((Join-Path $fixture 'notes.md'), $utility)
    [IO.File]::WriteAllText($view, $utility)
    Invoke-Checked $pnpm @('build')
    if ((Get-AssetDigest) -ne $baseline) { throw 'Unrelated Markdown or generated views changed production assets.' }
    Write-Host 'PASS production assets identical with unrelated Markdown and generated views.'
} finally {
    if ([IO.Path]::GetDirectoryName($fixture) -ne $root -or -not [IO.Path]::GetFileName($fixture).StartsWith('verify-build-fixture-')) { throw 'Build fixture cleanup guard failed.' }
    Assert-PlainPath $fixture
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
    if (Test-Path -LiteralPath $view) { Remove-Item -LiteralPath $view -Force }
}
