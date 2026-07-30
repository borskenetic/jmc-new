# JMC Offline Gate Terminal

Local gate app for **student** scanning when internet is down. **Visitors still use the online gate.**

Gate choosing works the same idea as the online kiosk: pick a gate before scanning. When online, occupancy is shared with browser terminals.

---

## For guards (daily use)

1. Double-click **`Start Gate.bat`** (or desktop shortcut)
2. Choose this PC's **gate** when prompted
3. For testing without a scanner: **`Start Gate (Test Mode).bat`** or press **F2**
4. Wait for the scan screen to open
5. **Minimize** the black window titled **"DO NOT CLOSE"** — do not close it
6. Scan student IDs as usual

Read **`GUARD INSTRUCTIONS.txt`** for full steps.

---

## For IT (first install on gate PC)

### Requirements

- Windows 10/11
- [Node.js 18+](https://nodejs.org) (LTS)
- Gate device token from **Admin → Attendance → Offline Gate Devices**

### Steps

1. Copy the whole `gate-terminal` folder to the gate PC
2. On the server: `php artisan migrate`
3. Admin → **Offline Gate Devices** → register device → copy token
4. Double-click **`Setup Gate (First Time).bat`**
5. Edit `config.json` when Notepad opens (`cloud_url` + `device_token`)
6. Double-click **`Create Desktop Shortcut (IT).bat`**

### Daily (guards)

| File | Purpose |
|------|---------|
| **Start Gate.bat** | Start gate + open scan screen |
| **Stop Gate.bat** | Stop server (end of day / troubleshooting) |
| **Sync Now.bat** | Force upload/download (IT only) |

---

## Manual commands (optional)

```bash
npm install
npm start
npm run sync
```

Scan screen: **http://127.0.0.1:9173**

---

## Notes

- Offline rules match JMC online scanning: last log IN ↔ OUT, plus grade-school early OUT block
- Online browser gate picker (`/attendance`) is unchanged
- Visitors: use the online gate only

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Node.js is not installed" | Install from nodejs.org, restart PC, run Setup again |
| "Gate is not set up yet" | Run **Setup Gate (First Time).bat** |
| "Select a gate…" | Use the gate picker (Change in the header badge) |
| Scans don't appear | Is the black server window still open? |
| Unknown student offline | Connect internet; run **Sync Now.bat** |
| Visitors | Use online gate at `/attendance` |

If `npm install` fails on `better-sqlite3`, install [Visual Studio Build Tools](https://visualstudio.microsoft.com/visual-cpp-build-tools/) → "Desktop development with C++", then run Setup again.
