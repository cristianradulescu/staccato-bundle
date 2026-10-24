# Local Development & Usage

To work on the bundle and try your changes in a real site at the same time, use a **Composer Path Repository** to link your local `staccato` folder to a Symfony project.

## Usage Instructions

1. **Create a new Symfony 8 project** in a directory adjacent to your `staccato` folder (e.g., in `../my-site`).
2. **Edit the new project's `composer.json`** to include the local path to the bundle:
   ```json
   "repositories": [
       {
           "type": "path",
           "url": "../staccato"
       }
   ],
   ```
3. **Install the bundle** via terminal in your new project's root:
   ```bash
   composer require cristianradulescu/staccato-bundle
   ```
4. **Configure the bundle** by creating `config/packages/staccato.yaml` in your new project:
   ```yaml
   staccato:
       title: My Site
       base_url: https://example.com
       description: A simple static site
       articles_per_page: 10
       footer_text: Built with Staccato
   ```
5. **Build the site** using the bundle's command (ensure you have a `content/` directory with Markdown files):
   ```bash
   php bin/console site:build
   ```
