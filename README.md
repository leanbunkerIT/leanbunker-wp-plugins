<p align="center">
  <img src="https://avatars.githubusercontent.com/u/275549537?v=4&size=64" alt="Logo" width="100" style="border-radius: 50%; box-shadow: 0 0 20px rgba(220, 20, 20, 0.5);">
</p>

<h1 align="center" style="color: #d32f2f; font-size: 3em; margin-bottom: 0;">LEAN BUNKER</h1>
<h3 align="center" style="color: #586069; font-weight: 300; margin-top: 0;">WP Plugins Community Collection</h3>

<p align="center">
  <a href="https://github.com/leanbunker/leanbunker-wp-plugins"><img src="https://img.shields.io/badge/PHP-100%25-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-CMS-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://github.com/riccardobasti"><img src="https://img.shields.io/badge/Maintainer-riccardobasti-red?style=for-the-badge&logo=github" alt="Maintainer"></a>
</p>

---

### 🚀 About
Welcome to the official **Lean Bunker** community repository. Here you'll find a complete suite of WordPress plugins developed to enhance, automate, and improve your website. From the core framework to AI and SEO tools.

---

### 📦 Plugin Collection

#### 🧱 Core & Framework
| Plugin | Description |
| :--- | :--- |
| 🔷 **lean-bunker-framework** | Creates CPTs, fields, and math calculations. |
| 🔷 **lean-bunker-clone** | Tools for cloning pages, posts, and CPTs. |
| 🔷 **lean-bunker-autolang** | Automation for multilingual content management. |

#### 🤖 AI & Automation
| Plugin | Description |
| :--- | :--- |
| 🤖 **lean-bunker-html-ai** | HTML generation and optimization via Artificial Intelligence. |
| 🤖 **lean-bunker-autopost** | Automatic content publishing from titles and feeds. |
| 🤖 **lean-bunker-sitemap-autopost** | Automatic content publishing from sitemaps. |
| 🤖 **lean-bunker-frontend-post** | Allow users to publish content directly from the frontend. |

#### 🛒 Commerce & Content
| Plugin | Description |
| :--- | :--- |
| 🛒 **lean-bunker-commerce** | B2B and B2C eCommerce solution for WordPress. |
| 📝 **lean-bunker-archivie-comment** | Advanced comment management in archive pages. |
| 🔗 **lean-bunker-semantic-linker** | Optimization of semantic internal linking. |

#### 🎨 Design & SEO
| Plugin | Description |
| :--- | :--- |
| 🔍 **lean-bunker-seo** | Essential tools for Search Engine Optimization. |
| 🎨 **lean-bunker-style-table** | Advanced styling for WordPress tables. |

---

### 🔎 Descrizione Dettagliata dei Plugin

#### 🧱 Core & Framework

<details>
<summary>🔷 <strong>lean-bunker-framework</strong> — Costruttore di Strutture Dati Nativo WP</summary>

Un **costruttore di strutture dati nativo WordPress** senza dipendenze esterne. Permette di:
- Creare **Custom Post Types (CPT)** visualmente dall'area admin
- Definire **campi personalizzati** di vari tipi (testo, select, relazione, ecc.)
- Configurare **formule matematiche** tra campi
- Creare **gruppi** e **relazioni** tra entità
- Esporre i dati tramite shortcode (`[wpnb_display]`, `[wpnb_form]`) sia in visualizzazione che come form frontend
</details>

<details>
<summary>🔷 <strong>lean-bunker-clone</strong> — Clonazione di Post, Pagine e CPT</summary>

Plugin minimalista per **clonare articoli, pagine e CPT** con un clic. Aggiunge un link "Clona" nelle liste admin. La copia include titolo, contenuto, metadati e tassonomie, e viene salvata automaticamente come bozza pronta per la modifica.
</details>

<details>
<summary>🔷 <strong>lean-bunker-autolang</strong> — Traduzione Automatica Multilingua con SEO</summary>

Plugin di **traduzione automatica** tramite Google Translate con ottimizzazione SEO completa:
- Supporta 14 lingue: `it, en, de, fr, es, pt, ru, ja, zh-CN, ar, pl, nl, sv, tr`
- Gestisce **URL multilingua** (`/en/`, `/de/`, ecc.) tramite WordPress rewrite rules
- Inietta automaticamente tag **hreflang**, **canonical** self-referential e markup **schema.org** multilingua
- Integra con la **sitemap nativa WordPress** (5.5+)
</details>

---

#### 🤖 AI & Automation

<details>
<summary>🤖 <strong>lean-bunker-html-ai</strong> — Generazione HTML con AI nell'Editor</summary>

Genera **HTML strutturato con AI** direttamente nell'editor classico di WordPress:
- Aggiunge una **metabox** laterale con campo prompt in ogni post/pagina
- Usa l'API di **Together.xyz** (modello `Qwen2.5-7B-Instruct-Turbo`)
- Il prompt ottimizzato forza output HTML semantico con classi predefinite (section, h2, CTA, FAQ)
- Inserisce il risultato nell'editor (supporta TinyMCE, LB Native Editor e textarea classica)
- Configurabile con la propria API key dalla pagina impostazioni
</details>

<details>
<summary>🤖 <strong>lean-bunker-autopost</strong> — Pubblicazione Automatica da Sitemap con AI</summary>

**Pubblicazione automatica di articoli** da sitemap esterne tramite AI:
- Ogni 5 minuti (WP-Cron) legge gli URL da sitemap configurate e genera nuovi contenuti
- Riscrive il contenuto degli articoli sorgente tramite l'API **Together AI**
- Configurabile per post type, categoria, tassonomia e prompt personalizzato
- Tiene traccia degli URL già processati per evitare duplicati
</details>

<details>
<summary>🤖 <strong>lean-bunker-sitemap-autopost</strong> — Aggregatore News da Sitemap con AI</summary>

**Aggregatore di news da sitemap** con riscrittura AI avanzata:
- Funzionalità simili a `lean-bunker-autopost` con miglioramenti aggiuntivi
- Supporto alla **citazione della fonte** originale nell'articolo generato
- **Batch processing** configurabile (fino a 5 articoli per ciclo)
- Estrazione testo pulita con fallback intelligente e validazione della riscrittura
</details>

<details>
<summary>🤖 <strong>lean-bunker-frontend-post</strong> — Creazione Articoli dal Frontend</summary>

Permette agli utenti registrati di **creare e gestire articoli direttamente dal frontend** tramite due shortcode:
- `[frontend_post_form]` — form completo per creare/modificare articoli (editor WYSIWYG, upload immagine, categoria, tag)
- `[frontend_post_list]` — tabella dei propri articoli con azioni di modifica, eliminazione e visualizzazione dello stato
- Gli articoli vengono inviati in stato `pending` per la revisione dell'amministratore
</details>

---

#### 🛒 Commerce & Content

<details>
<summary>🛒 <strong>lean-bunker-commerce</strong> — eCommerce B2B/B2C Nativo WordPress</summary>

Un sistema **eCommerce B2B e B2C completo** in un singolo file PHP, senza dipendenze da WooCommerce:
- **Aree private** con categorie utente personalizzate (B2B/B2C)
- **Configuratore prodotti** visuale con opzioni e varianti
- **Carrello** e **checkout** nativi
- **CPT ordini** (`lb_order`) con tracciamento dello stato
- Shortcode disponibili: `[aggiungi_al_carrello]`, `[carrello]`, `[checkout]`, `[miei_ordini]`, `[miei_acquisti]`, `[lean_calculator]`, `[ordine_completato]`
- Compatibile con **WordPress Multisite**
</details>

<details>
<summary>📝 <strong>lean-bunker-archivie-comment</strong> — Commenti Compatti negli Archivi</summary>

Plugin per visualizzare **commenti compatti e un form di invio direttamente nelle pagine archivio** di WordPress:
- Mostra gli ultimi 3 commenti approvati per ogni post nell'archivio
- Invio commenti via **AJAX** senza ricaricare la pagina
- **Zero paginazione** nei listing: esperienza fluida e compatta
- Metabox nell'editor per attivare/disattivare la funzione per ogni singolo post
- Shortcode `[wp_social_comments]` per inserimento manuale
</details>

<details>
<summary>🔗 <strong>lean-bunker-semantic-linker</strong> — Link Interni Semantici Automatici</summary>

Crea automaticamente **topic cluster con link interni semantici** per migliorare la struttura SEO del sito:
- Admin UI completa con dashboard, guida display, debug e documentazione
- **Safe Mode** con protezioni SEO avanzate per evitare errori
- Filtri semantici configurabili per pertinenza e qualità dei link
- Impostazioni per post type inclusi, limite di link per articolo, posizione di inserimento e stile di visualizzazione
</details>

---

#### 🎨 Design & SEO

<details>
<summary>🔍 <strong>lean-bunker-seo</strong> — SEO Completo All-in-One</summary>

Plugin SEO completo (alternativa zero-dipendenze a Yoast/RankMath) in un singolo file:
- **Meta title e description** personalizzabili per ogni post e tassonomia
- Tag **Open Graph** (Facebook) e **Twitter Cards** automatici
- Markup **Schema.org** automatico (Article, WebPage, BreadcrumbList, Organization, ecc.)
- **Breadcrumb** tramite shortcode `[lb_breadcrumb]`
- **Ping automatico** della sitemap a Google, Bing e Yandex ad ogni pubblicazione
- **Knowledge Graph** semantico automatico
- Supporto **llms.txt** con direttive per AI crawler
- Generazione meta con AI (Together API)
- Direttive `robots.txt` per la gestione dell'accesso AI (`noai`, `noimageai`)
</details>

<details>
<summary>🎨 <strong>lean-bunker-style-table</strong> — Tabelle Responsive e Moderne</summary>

Applica automaticamente **stili moderni e responsive** a tutte le tabelle WordPress:
- Wrappa ogni `<table>` in un contenitore con scroll orizzontale su mobile
- Effetto zebra, hover interattivo e intestazioni stilizzate
- Nessuna configurazione richiesta: si attiva automaticamente su tutto il contenuto
- Shortcode `[uni_table]` disponibile per inserimento manuale
</details>

---

### 💻 Tech Stack
<p align="center">
  <img src="https://skillicons.dev/icons?i=php,wordpress,git,github" alt="Tech Stack" />
</p>

### 👥 Contributors
Thanks to those who make this project possible:
<br>
<a href="https://github.com/riccardobasti">
  <img src="https://github.com/riccardobasti.png" width="60px;" alt="Riccardo Basti" style="border-radius: 50%; border: 2px solid #d32f2f;"/>
</a>
<a href="https://github.com/seowpitalia">
  <img src="https://github.com/seowpitalia.png" width="60px;" alt="wpseoitalia" style="border-radius: 50%; border: 2px solid #d32f2f;"/>
</a>
<a href="https://github.com/datRooster">
  <img src="https://github.com/datRooster.png" width="60px;" alt="datRooster" style="border-radius: 50%; border: 2px solid #d32f2f;"/>
</a>

---

<p align="center">
  <sub>Made with ❤️ by the Lean Bunker Community</sub>
</p>
