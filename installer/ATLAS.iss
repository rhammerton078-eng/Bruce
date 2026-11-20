; ATLAS · Mnemo installer (Inno Setup 6). Built by "Build ATLAS installer.bat",
; which copies everything into the stage folder first and passes it as /DStage=...
#ifndef Stage
  #define Stage "C:\ATLAS-build\stage"
#endif
#define AppName "ATLAS - Mnemo AI"
#define AppVersion "1.0.0"
#if FileExists(Stage + "\ATLAS.exe")
  #define Launch "ATLAS.exe"
#else
  #define Launch "Start ATLAS.bat"
#endif

[Setup]
AppId={{6E7A1C52-9B1D-4E0B-A7F3-5C2D8E4B9A61}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Robert Bruce Jr. C. Hammerton
VersionInfoDescription=ATLAS - offline AI that learns from your files
DefaultDirName={sd}\ATLAS
DisableProgramGroupPage=yes
PrivilegesRequired=admin
OutputBaseFilename=ATLAS-Setup
SetupIconFile={#Stage}\atlas.ico
UninstallDisplayIcon={app}\atlas.ico
LicenseFile={#Stage}\NOTICE.txt
Compression=lzma2/fast
SolidCompression=no
DiskSpanning=yes
DiskSliceSize=max
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
ExtraDiskSpaceRequired=2147483648

[Tasks]
Name: "desktopicon"; Description: "Create a desktop icon"; GroupDescription: "Shortcuts:"

[Files]
Source: "{#Stage}\Start ATLAS.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\Stop ATLAS.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\atlas.ico"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\NOTICE.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\ATLAS.exe"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "{#Stage}\Microsoft.Web.WebView2.Core.dll"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "{#Stage}\Microsoft.Web.WebView2.WinForms.dll"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "{#Stage}\WebView2Loader.dll"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "{#Stage}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "{#Stage}\xampp\*"; DestDir: "{app}\xampp"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\ollama\*"; DestDir: "{app}\ollama"; Flags: ignoreversion recursesubdirs createallsubdirs
; "Download my AI": the memory and the name sit next to ATLAS-Setup.exe and are picked up at install time
Source: "{src}\mnemo-package\memory.atlascard"; DestDir: "{app}\package"; Flags: external skipifsourcedoesntexist ignoreversion
Source: "{src}\mnemo-package\identity.json"; DestDir: "{app}\package"; Flags: external skipifsourcedoesntexist ignoreversion
; AI model files are already compressed: store them as they are (much faster)
Source: "{#Stage}\models\*"; DestDir: "{app}\models"; Flags: ignoreversion recursesubdirs createallsubdirs nocompression

[Icons]
Name: "{autoprograms}\ATLAS - Mnemo AI"; Filename: "{app}\{#Launch}"; WorkingDir: "{app}"; IconFilename: "{app}\atlas.ico"
Name: "{autoprograms}\Stop ATLAS"; Filename: "{app}\Stop ATLAS.bat"; WorkingDir: "{app}"; IconFilename: "{app}\atlas.ico"
Name: "{autodesktop}\ATLAS - Mnemo AI"; Filename: "{app}\{#Launch}"; WorkingDir: "{app}"; IconFilename: "{app}\atlas.ico"; Tasks: desktopicon

[Run]
Filename: "{app}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"; Parameters: "/silent /install"; StatusMsg: "Installing the window component (Microsoft WebView2)..."; Flags: waituntilterminated skipifdoesntexist; Check: NeedsWebView2
Filename: "{app}\{#Launch}"; Description: "Open it now"; Flags: postinstall nowait skipifsilent shellexec

[UninstallRun]
Filename: "{app}\Stop ATLAS.bat"; Flags: runhidden waituntilterminated; RunOnceId: "StopAtlas"

[UninstallDelete]
Type: files; Name: "{app}\.location"
Type: filesandordirs; Name: "{app}\package"

[Code]
function NeedsWebView2: Boolean;
var v: String;
begin
  Result := not (RegQueryStringValue(HKLM, 'SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', v) and (v <> '') and (v <> '0.0.0.0'))
        and not (RegQueryStringValue(HKCU, 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', v) and (v <> '') and (v <> '0.0.0.0'));
end;
