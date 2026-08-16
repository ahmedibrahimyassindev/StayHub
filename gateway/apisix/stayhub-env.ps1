function Get-StayHubEnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Name,
        [string] $Default = $null,
        [switch] $Required
    )

    $processValue = [Environment]::GetEnvironmentVariable($Name, 'Process')

    if (-not [string]::IsNullOrWhiteSpace($processValue)) {
        return $processValue
    }

    $envFile = Join-Path $PSScriptRoot '..\..\.env'

    if (Test-Path $envFile) {
        foreach ($line in Get-Content -Path $envFile) {
            if ($line -match "^\s*$([regex]::Escape($Name))\s*=\s*(.*)\s*$") {
                $value = $Matches[1].Trim().Trim('"').Trim("'")

                if (-not [string]::IsNullOrWhiteSpace($value)) {
                    return $value
                }
            }
        }
    }

    if ($null -ne $Default) {
        return $Default
    }

    if ($Required) {
        throw "Missing required setting $Name. Set it in the process environment or root .env file."
    }

    return $null
}
