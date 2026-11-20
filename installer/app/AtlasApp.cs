// ATLAS desktop app: one window for the downloaded AI. No browser, no address bar, no tabs.
// Starts the hidden background parts (memory, web server, AI, learner), shows ATLAS inside its own
// window (Microsoft WebView2), and stops everything again when the window is closed.
// Compiled by "Build ATLAS installer.bat" with the C# compiler that comes with Windows (C# 5).
using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace AtlasApp
{
    static class Program
    {
        [STAThread]
        static void Main()
        {
            bool first;
            using (var mutex = new Mutex(true, "ATLAS-Mnemo-App", out first))
            {
                if (!first) { MessageBox.Show("Your AI is already open.", "ATLAS", MessageBoxButtons.OK, MessageBoxIcon.Information); return; }
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Application.Run(new MainForm());
            }
        }
    }

    class MainForm : Form
    {
        const string Url = "http://localhost:8765/atlas/";
        readonly string root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');
        WebView2 web;
        Label status;
        bool closing;

        public MainForm()
        {
            Text = AiName();
            try { Icon = new Icon(Path.Combine(root, "atlas.ico")); } catch { }
            BackColor = Color.FromArgb(7, 9, 13);
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(1500, 920);
            MinimumSize = new Size(900, 600);

            status = new Label();
            status.Dock = DockStyle.Fill;
            status.TextAlign = ContentAlignment.MiddleCenter;
            status.ForeColor = Color.FromArgb(185, 196, 214);
            status.Font = new Font("Segoe UI", 14f);
            status.Text = "Starting " + AiName() + "...";
            Controls.Add(status);

            Shown += async (s, e) => await StartAsync();
            FormClosing += OnClosing;
        }

        string AiName()
        {
            try
            {
                string f = Path.Combine(root, "package", "identity.json");
                if (File.Exists(f))
                {
                    string j = File.ReadAllText(f);
                    int i = j.IndexOf("\"ai_name\"");
                    if (i >= 0)
                    {
                        int a = j.IndexOf('"', j.IndexOf(':', i) + 1);
                        int b = j.IndexOf('"', a + 1);
                        if (a > 0 && b > a) return System.Text.RegularExpressions.Regex.Unescape(j.Substring(a + 1, b - a - 1));
                    }
                }
            }
            catch { }
            return "ATLAS - Mnemo AI";
        }

        void RunHidden(string bat, string args, bool wait)
        {
            var psi = new ProcessStartInfo("cmd.exe", "/c \"\"" + Path.Combine(root, bat) + "\" " + args + "\"");
            psi.WorkingDirectory = root;
            psi.CreateNoWindow = true;
            psi.UseShellExecute = false;
            psi.WindowStyle = ProcessWindowStyle.Hidden;
            var p = Process.Start(psi);
            if (wait && p != null) p.WaitForExit(120000);
        }

        static bool Ready()
        {
            try
            {
                var req = (HttpWebRequest)WebRequest.Create(Url + "setup.php");
                req.Timeout = 2000;
                using (var res = (HttpWebResponse)req.GetResponse()) return (int)res.StatusCode < 500;
            }
            catch { return false; }
        }

        async Task StartAsync()
        {
            // 1. Background parts (hidden). "nobrowser" = do not open a browser window.
            await Task.Run(() => RunHidden("Start ATLAS.bat", "nobrowser", true));
            for (int i = 0; i < 90 && !Ready(); i++) { status.Text = "Starting " + AiName() + "..." + new string('.', i % 4); await Task.Delay(1000); }

            // 2. Show ATLAS inside this window
            try
            {
                web = new WebView2();
                web.Dock = DockStyle.Fill;
                web.DefaultBackgroundColor = Color.FromArgb(7, 9, 13);
                string data = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ATLAS", "WebView2");
                var env = await CoreWebView2Environment.CreateAsync(null, data);
                Controls.Add(web);
                await web.EnsureCoreWebView2Async(env);
                var cw = web.CoreWebView2;
                cw.Settings.AreDevToolsEnabled = false;
                cw.Settings.IsStatusBarEnabled = false;
                cw.Settings.AreBrowserAcceleratorKeysEnabled = false;
                cw.DocumentTitleChanged += (s, e) => { if (!string.IsNullOrEmpty(cw.DocumentTitle)) Text = cw.DocumentTitle.Replace(" · ATLAS", ""); };
                cw.NewWindowRequested += (s, e) => { e.Handled = true; cw.Navigate(e.Uri); };   // keep everything in this window
                cw.Navigate(Url);
                status.Visible = false;
                web.BringToFront();
            }
            catch (Exception ex)
            {
                // No WebView2 runtime on this PC: open as an app window in Edge instead
                status.Text = "Opening " + AiName() + "...";
                try { Process.Start("msedge.exe", "--app=" + Url + " --window-size=1500,920"); closing = true; Close(); }
                catch { status.Text = "Could not open the window: " + ex.Message + "\n\nOpen " + Url + " in a browser."; }
            }
        }

        void OnClosing(object sender, FormClosingEventArgs e)
        {
            if (closing) return;
            closing = true;
            e.Cancel = true;
            Hide();
            // Save the memory cleanly and stop the background parts, then exit
            Task.Run(() => { try { RunHidden("Stop ATLAS.bat", "", true); } catch { } }).ContinueWith(t => BeginInvoke(new Action(() => { if (web != null) web.Dispose(); Application.Exit(); })));
        }
    }
}
