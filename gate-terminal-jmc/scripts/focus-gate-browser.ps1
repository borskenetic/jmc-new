# Force the gate browser window to maximize / cover the kiosk display.
# SW_MAXIMIZE = 3 (NOT SW_RESTORE = 9 — that kept windows at ~70% size).

$ErrorActionPreference = 'SilentlyContinue'

Add-Type -Namespace GateWin -Name Native -MemberDefinition @"
[DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
[DllImport("user32.dll")] public static extern bool MoveWindow(IntPtr hWnd, int X, int Y, int nWidth, int nHeight, bool bRepaint);
[DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
"@

$swMaximize = 3
$names = @('msedge', 'chrome', 'ApplicationFrameHost')

# Prefer newest browser window (just launched)
$candidates = foreach ($n in $names) {
    Get-Process -Name $n -ErrorAction SilentlyContinue |
        Where-Object { $_.MainWindowHandle -ne [IntPtr]::Zero -and [GateWin.Native]::IsWindowVisible($_.MainWindowHandle) }
}

$proc = $candidates | Sort-Object StartTime -Descending | Select-Object -First 1
if (-not $proc) { exit 0 }

$hwnd = $proc.MainWindowHandle

# Maximize first
[void][GateWin.Native]::ShowWindow($hwnd, $swMaximize)
[void][GateWin.Native]::SetForegroundWindow($hwnd)

# Also pin to primary screen bounds (helps odd DPI / 1440x900 kiosks)
Add-Type -AssemblyName System.Windows.Forms
$screen = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
[void][GateWin.Native]::MoveWindow($hwnd, $screen.X, $screen.Y, $screen.Width, $screen.Height, $true)
[void][GateWin.Native]::ShowWindow($hwnd, $swMaximize)
[void][GateWin.Native]::SetForegroundWindow($hwnd)

exit 0
