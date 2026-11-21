$phpPath = 'C:\xampp\php\php.exe'
if (-not (Test-Path $phpPath)) {
    throw "PHP was not found at $phpPath. Check your XAMPP installation path."
}

$previousPassword = $env:MFC_DB_PASS
$securePassword = Read-Host 'Enter your local MySQL password (input is hidden)' -AsSecureString
$passwordPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $env:MFC_DB_PASS = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)

    Write-Host 'Keep this window open and use a browser to visit http://127.0.0.1:8000/index.php'
    Write-Host 'Do not open PHP files by double-clicking them; they must be served from this address.'
    & $phpPath -S 127.0.0.1:8000 -t $PSScriptRoot
}
finally {
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    $securePassword.Dispose()

    if ($null -eq $previousPassword) {
        Remove-Item Env:MFC_DB_PASS -ErrorAction SilentlyContinue
    }
    else {
        $env:MFC_DB_PASS = $previousPassword
    }
}
