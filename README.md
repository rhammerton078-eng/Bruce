# ATLAS · Mnemo

**ATLAS: Autonomous Knowledge Reconstruction and Relationship Discovery System Using Semantic Graph Intelligence**

Mnemo is the AI inside ATLAS. It learns from everything you upload or teach it, builds a knowledge graph in your
XAMPP MySQL database, and answers questions with citations. It runs 100% offline.

## Lab PC with Deep Freeze (quick start)

After every restart:
1. github.com/rhammerton078-eng/Bruce → **Code → Download ZIP**, extract into `C:\xampp\htdocs`, rename `Bruce-main` → `atlas`.
2. Right-click **`ATLAS Lab Setup.bat`** → **Run as administrator**. It finds or downloads Ollama + Qwen, starts XAMPP,
   Ollama and the background learner, and opens the setup page.
3. **Install database**, open ATLAS, and **Insert** your `.atlascard` to restore Mnemo's memory.

The AI (Ollama + Qwen, about 10 GB) is too big for GitHub. Copy `C:\ATLAS-AI` to a USB drive as `X:\ATLAS-AI`;
the setup script then uses it and downloads nothing.

## Install (Windows + XAMPP)

1. Extract this folder to `C:\xampp\htdocs\atlas`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Install **Ollama** from ollama.com, then open a terminal and run:
   ```
   ollama pull qwen2.5:7b
   ollama pull nomic-embed-text
   ollama pull qwen2.5vl:7b
   ```
   (8 GB RAM: use `qwen2.5:3b` and skip the vision model. 32 GB+: `qwen2.5:14b` gives better extraction.)
4. Open <http://localhost/atlas/setup.php>, click **Install database**, then **Open ATLAS**.
5. Recommended in `C:\xampp\php\php.ini`: `upload_max_filesize = 128M`, `post_max_size = 128M`,
   `max_execution_time = 300`, then restart Apache.

Optional: Poppler (`pdftotext`, `pdftoppm`) for better PDFs and scanned-PDF OCR; Tesseract for text inside images.
Add them to PATH or set their full paths in `config.php`.

## Settings & GitHub

`config.php` holds the shared defaults and is safe to commit. Put **your** settings (MySQL port, password,
model names) in `config.local.php`. Copy `config.local.example.php` to start. It overrides `config.php`, is
listed in `.gitignore`, and is never overwritten by `git pull`. Uploaded files, backups and memory cards
(`storage/…`) are not committed either. Mnemo's memory lives in MySQL; move it between PCs with memory cards.

## Using it

| Where | What |
|---|---|
| **Teach** button / drag-and-drop anywhere | Upload PDF, DOCX, PPTX, TXT, MD, HTML, CSV, JSON, PNG, JPG, WEBP |
| `storage/inbox/` | Drop files here and they're ingested automatically |
| **Graph** | 2D/3D explorer. Colour by topic or type, filter, path finder, click to inspect |
| **Ask Mnemo** | Cited answers. `remember:`, `define:` and `correct:` teach it directly. Time travel answers "as of" a date |
| **Sources** | Status, progress, collection, trust, retry, forget |
| **Timeline** | Everything Mnemo learned, day by day |
| **Overview** | Growth chart, digest, most-connected concepts, knowledge gaps, learning streak |
| **Sleep cycle** | Infers new links, discovers topic clusters, recomputes confidence, writes the daily digest |

Keyboard: `Ctrl+K` search · `1`–`9` and `0` switch views · `U` upload · `?` tutorial.

## Memory cards (like a memory card in a phone)

Each card is its own MySQL database plus its own files folder. Exactly one card is active at a time.

- **Card pill** in the top bar (or Memory → Memory cards): switch, insert, eject, or create a blank card.
- **Eject** saves a card as one `.atlascard` file (the whole MySQL memory + original files), optionally
  **password-protected** (AES-256). Tick "remove from this PC" and Mnemo forgets it until you insert it again.
- **Insert** a card file on any PC running ATLAS: it shows the card's label first (name, sources, concepts), then adds
  it next to your other cards and makes it active. Nothing is overwritten.
- **New blank card** = a newborn Mnemo with zero knowledge.
- Card databases are named `<db>_c_<id>`; card files live in `storage/cards/<id>/`. The registry is `storage/cards.json`.
- The background worker and nightly sleep cycle work on the active card.

## Baby mode (Grow tab)

Mnemo starts as a **Newborn** that knows nothing and grows without limit as you feed it.

- **Strict mode** (on by default): Mnemo only knows what you taught it. With nothing relevant in memory it answers
  "I don't know that yet" without consulting the model's own knowledge.
- **Levels never end**: every source, concept, fact, answer and decision adds XP. Newborn → Infant → Toddler → Child →
  Student → Graduate → Scholar → Expert → Master → Sage → Polymath I, II, III …
- **Mnemo asks you**: questions about things it has seen but nobody explained. Your answers become top-trust knowledge.
- **Lessons & exams**: one fact per line, then "Examine Mnemo" for a school grade (A–F).
- **Growth diary**: written after each sleep cycle. **Forgetting** is optional and off by default.

Note: the language model itself is pre-trained (it needs that to read and write). What starts from zero and grows
is Mnemo's knowledge. To also grow the model itself, see `docs/finetune.html`.

## Tutorial mode

Click the **graduation-cap** button in the left rail (or press `?`) to open **Learn ATLAS**:

- **10 guided chapters** that highlight each part of the screen and explain it step by step (Welcome · Teaching · Graph · Ask &
  teach · Truth & review · Practice · Quality · Memory & safety · Grow · Daily routine). Some steps have "Try it" buttons.
- A **getting-started checklist** of 12 hands-on tasks that ticks itself off as you actually do them, each with a "Show me" link.
- A **demo knowledge pack** (4 astronomy documents, including an outdated 1995 textbook so you can practise resolving a real
  contradiction, plus 3 benchmark questions). Load or remove it with one click.
- A **?** button on every screen opens that screen's chapter.
- The hub opens automatically the first time ATLAS starts with an empty memory.

## Truth, learning and practice

| Tab | What it does |
|---|---|
| **Review** | **Contradictions**: facts from different sources that disagree; you pick the winner and Mnemo stops trusting the loser. **Facts to verify**: links whose quoted evidence wasn't found in the text. **Duplicates**: concepts that look like the same thing (merge, with undo). **New connections**: unlinked concepts that share neighbours. |
| **Practice** | Spaced-repetition flashcards made from your knowledge (keys: Space flip, 1–4 grade). |
| **Quality** | Benchmark questions scored over time ("is Mnemo getting smarter?"), Mnemo's self-quiz, approved answers, training-data export and a fine-tuning guide. |
| **Ask → Watchlist** | Standing questions re-answered automatically when new knowledge changes the answer. |
| **Camera icon** in search | Search your knowledge with an image. |

- Every fact Mnemo extracts is stored as a **claim** and fact-checked against what it already knows.
- `correct:` facts automatically override conflicting source facts.
- Every change is kept in **fact history** (shown per concept).
- 👍 answers are reused for similar questions; 👎 removes them.
- Audio/video (MP3, WAV, MP4…) are transcribed offline once `ffmpeg`, `whisper` and `whisper_model` are set in `config.php`.
- MariaDB 11.7+ users can switch on the native vector index in Memory → Maintenance.

## Memory & storage (Memory tab)

- **Storage warnings**: database size and free disk space are shown in the status bar. You set the warning and critical
  levels; uploads pause automatically when the disk is almost full. The limits checker shows your php.ini / my.ini
  values next to the recommended ones.
- **Download the memory anytime**: *Back up database* (.sql.gz) or *Full backup* (database + original files, .zip).
  Backups are kept in `storage/backups`. A nightly automatic backup keeps the last 14.
- **Restore**: from any ATLAS backup file, even on another PC. A safety backup is made first.
- **Delete memory**: forget one source or concept, chat history, taught facts, the graph (it re-learns from sources),
  or **everything** (back to zero knowledge). You must type `DELETE`, and a safety backup is made first.
- **Maintenance**: rebuild the search index, re-embed after changing the embedding model, optimize tables, and export
  the knowledge as Markdown or the graph as JSON.

MySQL itself has no practical size limit (64 TB per InnoDB table). The real limits are disk space,
`upload_max_filesize` / `post_max_size` (php.ini) and `max_allowed_packet` (my.ini). See setup.php for the recommended values.

## Offline desktop app

- `launcher\Create Desktop Shortcut.bat`: run once. The **ATLAS** desktop icon then starts MySQL, Apache, Ollama and the
  background learner, and opens ATLAS in its own app window.
- `launcher\Stop ATLAS.bat` stops everything, and `launcher\Backup ATLAS now.bat` makes a full backup.
- Or in Edge/Chrome, use the menu → *Install ATLAS* to pin it like a normal program.

The project plan with diagrams is in `docs/plan.html`.

## Background workers (optional)

The browser processes uploads while ATLAS is open. To learn without the browser:

- `worker\run-worker.bat`: keeps processing uploads and the inbox folder.
- `worker\run-nightly.bat`: schedule daily in Windows Task Scheduler for the sleep cycle.

(Adjust the PHP path inside the .bat files if XAMPP is not in `C:\xampp`.)

## How it works

```
upload → parse (PDF/Office/OCR/vision) → chunk → embed (Ollama) → MySQL
       → LLM extracts entities + relations → knowledge graph (entities, relations, mentions)
ask    → hybrid retrieval (vectors + MySQL FULLTEXT + graph facts) → local LLM → cited answer
sleep  → similarity links · label-propagation topics · confidence · gaps · daily digest
```

All knowledge lives in the `atlas` MySQL database. Original files live in `storage/uploads`.
Back up both: `mysqldump -u root atlas > atlas.sql` and copy the `storage` folder.

## Security

The API and setup page only answer requests from localhost. To use ATLAS from other devices on your network,
create an empty file `storage/allow_lan`, and set a MySQL root password in `config.php`.

## Structure

```
index.php  setup.php  config.php
api/index.php            JSON + streaming API
src/                     Db, Schema, Ollama, DocParser, Chunker, Vector, Extractor, Graph, Ingest, Mnemo, Nightly,
                         Maintenance, Truth, Quality, Insights, Practice, Media, Cards, Growth, Tutorial
launcher/                Start / Stop / Desktop shortcut / Backup (.bat)
docs/plan.html           Visual project plan
docs/finetune.html       Optional LoRA fine-tuning guide
tutorial/demo/           Demo knowledge pack (used by tutorial mode)
worker/                  CLI ingest + nightly jobs (.bat launchers for Windows)
sql/schema.sql           Database schema (setup.php imports it)
assets/                  CSS, JS, bundled graph libraries (offline)
storage/                 uploads/, inbox/, backups/, cards/ (one folder per extra memory card)
```

## Presentation & lab backups

- `docs/presentation/ATLAS-Batuto-Format-UPDATED.pdf` (and `.pptx`): the 34-slide title-hearing deck, including the
  "System Features at a Glance" full-screen section. The added slides are in `docs/presentation/new-slides/`.
- `telegram/`: `ATLAS Restore from Telegram.bat` rebuilds the AI on a Deep Freeze lab PC from the files kept in Telegram
  (model parts + Ollama), and `TELEGRAM-CHECKLIST.txt` lists what to keep there. Eject a memory card before every shutdown.

## Branding, Mnemo's own model, installer and training

- **Name & logo**: `assets/icon.svg` (plus `icon-192.png`, `icon-512.png`, `atlas.ico`). Settings shows an About card with
  credits; third-party licenses are in `NOTICE.txt` ("Powered by Qwen 2.5").
- **`mnemo:7b`**: `ai/Modelfile` gives Mnemo its own model name in Ollama, built on `qwen2.5:7b` (instant, no download).
  `ATLAS Lab Setup.bat` creates it automatically; or run `ai\Create Mnemo model.bat`. Then Settings → Use it → Save.
- **Real training (fine-tuning)**: Quality → Training data, then open `ai/finetune/Mnemo_Train_Colab.ipynb` in Google Colab
  (free T4 GPU). Put the resulting `mnemo-7b-q4_k_m.gguf` in Downloads and run `ai\Install trained Mnemo.bat`
  to get `mnemo:7b-trained`. Compare both with Quality → Run benchmark.
- **Installer**: `installer\Build ATLAS installer.bat` (needs free Inno Setup 7 or 6) builds `C:\ATLAS-build\output\ATLAS-Setup.exe`
  with the web server, MySQL, ATLAS, Ollama and the models, either with a newborn Mnemo or with your trained memory.
  The installed copy uses its own ports (web 8765, MySQL 3307) and lives in `C:\ATLAS`.
- License note: `qwen2.5:7b`, `14b`, `32b` and `nomic-embed-text` are Apache 2.0. `qwen2.5:3b` and `qwen2.5vl:3b` use the
  Qwen Research license (non-commercial). Turn off the vision model before selling ATLAS.
