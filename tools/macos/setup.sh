#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
app="$PWD/build/Gestions Launcher.app"
mkdir -p "$app/Contents/MacOS"
xcrun swiftc Launcher.swift -o "$app/Contents/MacOS/GestionsLauncher" -framework Cocoa
python3 - "$app" "$@" <<'PY'
import os, plistlib, sys
app = sys.argv[1]
roots = sys.argv[2:] or [os.path.expanduser('~/Documents'), os.path.expanduser('~/Downloads')]
roots = [os.path.realpath(os.path.expanduser(p)) for p in roots]
if '/' in roots:
    raise SystemExit('No es permet autoritzar tot el disc.')
info = {
    'CFBundleExecutable': 'GestionsLauncher',
    'CFBundleIdentifier': 'cat.gestions.launcher',
    'CFBundleName': 'Gestions Launcher',
    'CFBundlePackageType': 'APPL',
    'CFBundleVersion': '1',
    'LSUIElement': True,
    'CFBundleURLTypes': [{'CFBundleURLName': 'Exportacions de Gestions', 'CFBundleURLSchemes': ['gestions']}],
    'GestionsAllowedDirectories': roots,
}
with open(os.path.join(app, 'Contents', 'Info.plist'), 'wb') as f:
    plistlib.dump(info, f)
print('Carpetes permeses:', *roots, sep='\n  ')
PY
codesign --force --sign - "$app"
/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -f "$app"
printf 'Auxiliar registrat: %s\n' "$app"
