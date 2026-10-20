import Cocoa

final class Launcher: NSObject, NSApplicationDelegate {
    func applicationDidFinishLaunching(_ notification: Notification) {
        // Opening the app manually should not leave a background process running.
        DispatchQueue.main.asyncAfter(deadline: .now() + 30) { NSApp.terminate(nil) }
    }

    func application(_ application: NSApplication, open urls: [URL]) {
        guard urls.count == 1,
              let parts = URLComponents(url: urls[0], resolvingAgainstBaseURL: false),
              parts.scheme == "gestions", parts.host == "open",
              let items = parts.queryItems, items.count == 1,
              items[0].name == "path", let path = items[0].value,
              path.hasPrefix("/") else { fail("L’enllaç de Gestions no és vàlid."); return }

        let file = URL(fileURLWithPath: path).standardizedFileURL.resolvingSymlinksInPath()
        let roots = Bundle.main.object(forInfoDictionaryKey: "GestionsAllowedDirectories") as? [String] ?? []
        let allowed = roots.contains { root in
            let directory = URL(fileURLWithPath: root).standardizedFileURL.resolvingSymlinksInPath().path
            return file.path.hasPrefix(directory + "/")
        }
        guard allowed, file.pathExtension.lowercased() == "xlsx",
              file.lastPathComponent.hasPrefix("lloguer-"),
              (try? file.resourceValues(forKeys: [.isRegularFileKey]).isRegularFile) == true else {
            fail("El fitxer no existeix o no és una exportació permesa. Revisa les carpetes configurades a tools/macos."); return
        }
        // Numbers Creator Studio i la versió anterior de Numbers tenen identificadors diferents.
        let numbersIdentifiers = ["com.apple.Numbers", "com.apple.iWork.Numbers"]
        guard let numbers = numbersIdentifiers.compactMap({
            NSWorkspace.shared.urlForApplication(withBundleIdentifier: $0)
        }).first else {
            fail("No s’ha trobat Numbers en aquest Mac."); return
        }
        NSWorkspace.shared.open([file], withApplicationAt: numbers, configuration: NSWorkspace.OpenConfiguration()) { _, error in
            DispatchQueue.main.async {
                if let error = error { self.fail(error.localizedDescription) }
                else { NSApp.terminate(nil) }
            }
        }
    }

    private func fail(_ message: String) {
        NSApp.setActivationPolicy(.regular)
        NSApp.activate(ignoringOtherApps: true)
        let alert = NSAlert()
        alert.messageText = "Gestions · Obrir amb Numbers"
        alert.informativeText = message
        alert.runModal()
        NSApp.terminate(nil)
    }
}
let delegate = Launcher()
let app = NSApplication.shared
app.delegate = delegate
app.setActivationPolicy(.accessory)
app.run()
