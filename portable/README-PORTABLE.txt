ATLAS PORTABLE - for PCs with Deep Freeze (or to carry ATLAS on a USB drive)
=========================================================================

Deep Freeze resets drive C: on every restart. ATLAS Portable keeps EVERYTHING
(web server, MySQL memory, AI program and AI models) in ONE folder on a drive
that is NOT frozen: a thawed D: drive, a ThawSpace, or an external USB SSD.

FOLDER LAYOUT (example on D:)
  D:\ATLAS\
     0 - Build in the AI (Ollama + Qwen).bat   (downloads the AI ONCE into this folder)
     1 - Setup Portable ATLAS.bat     (run once)
     2 - Start ATLAS.bat              (run every time)
     3 - Stop ATLAS.bat
     4 - Create Desktop Shortcut.bat  (run after each restart)
     5 - Start ATLAS from C htdocs.bat (alternative: serve ATLAS from C:\xampp\htdocs, memory on D:)
     xampp\                           (XAMPP installed here)
        htdocs\atlas\                 (ATLAS - atlas.zip extracted here)
        mysql\data\                   (Mnemo's memory)
     ollama\                          (portable Ollama, downloaded by step 1)
     models\                          (AI models, about 8 GB)

THE AI IS BUILT IN
  "0 - Build in the AI" downloads Ollama + Qwen once into the ollama\ and models\ folders
  (about 10 GB). After that, copy the whole ATLAS folder to any Windows PC (or run it from
  a USB drive): nothing is downloaded again and no internet is needed.
  Models already downloaded on C: (C:\Users\<you>\.ollama\models) are copied, not re-downloaded.

ONE-TIME SETUP
  1. Find a drive that survives a restart (see "TEST" below). Create D:\ATLAS
  2. Copy the 5 .bat files and this README from atlas\portable into D:\ATLAS
  3. Install XAMPP and choose  D:\ATLAS\xampp  as the installation folder
  4. Extract atlas.zip into  D:\ATLAS\xampp\htdocs
  5. Double-click "1 - Setup Portable ATLAS.bat"  (downloads Ollama + models)
  6. Double-click "2 - Start ATLAS.bat", open http://localhost/atlas/setup.php
     and click "Install database"
  7. Restart the PC and run "2 - Start ATLAS.bat" again: Mnemo still remembers.

TEST: does a drive survive Deep Freeze?
  Create a text file on the drive (e.g. D:\test.txt), restart the PC.
  Still there = OK to use.  Gone = frozen, use a USB drive or ask IT for a ThawSpace.

USB DRIVE TIPS
  - Use a USB 3 SSD (64 GB or more). A cheap USB flash stick is very slow for MySQL.
  - Always run "3 - Stop ATLAS.bat" before unplugging, or the memory can be damaged.
  - The drive letter may change on another PC; "2 - Start ATLAS.bat" fixes XAMPP's paths
    automatically.

PC ALREADY HAS ITS OWN MySQL (school / lab PCs)
  If another MySQL already uses port 3306, run YOUR MySQL on port 3307:
   - xampp\mysql\bin\my.ini : change every  port=3306  to  port=3307
   - xampp\htdocs\atlas\config.local.php : return ['db' => ['port' => 3307]];  (copy config.local.example.php)
   - XAMPP Control Panel > Config > Service and Port Settings > MySQL: 3307
  The Start/Stop scripts use port 3307 (set MYSQL_PORT at the top to change it).

MUST ATLAS RUN FROM C:\xampp\htdocs? (school XAMPP)
  Right-click "5 - Start ATLAS from C htdocs.bat" > Run as administrator, after every restart.
  It makes C:\xampp\htdocs\atlas a junction (doorway) to D:\ATLAS\xampp\htdocs\atlas, starts
  YOUR MySQL from D: on port 3307 and Ollama from D:, and uses the school's Apache on C:.
  Nothing important is stored on C:, so a restart loses nothing.

ALSO RECOMMENDED
  Make a memory card backup regularly: ATLAS > card pill (top bar) > Eject...
  Keep the .atlascard file on a second drive or in the cloud.
