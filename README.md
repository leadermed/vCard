# WP VCard Generator

WordPress plugin that generates downloadable vCard (`.vcf`) contact files for employees from ACF fields.

## Features

- "Générer une VCARD" button on the employee edit screen (AJAX).
- WP-Cron job (every 12 hours) that generates the cards that are missing.
- Bilingual (FR/EN) job title, using the translated employee post.
- Output escaped for the vCard 3.0 format.

## Requirements

- WordPress 5.2+ and PHP 7.2+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/)
- A custom post type named `employees`
- Polylang (or WPML) for the bilingual job title. The plugin still works without it.

## Installation

1. Copy the `wp-vcard-generator` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Open an employee and click **Générer une VCARD**. The file URL is saved in the `url_de_carte` ACF field.

## Project structure

```
vcard-generator-plugin.php   Main file: hooks, AJAX handler, cron, vCard builder
assets/js/script.js          Button behavior (AJAX call with nonce)
assets/css/style.css         Admin styles
index.php                    Prevents directory listing
```

## Design notes

- `wp_vcard_generate_for_post()` holds the generation logic. The AJAX handler and the cron job both call it, so nothing is duplicated and the cron does not depend on `$_POST`.
- Employee data stays in ACF, where the client's team already manages it.
- Generated files are stored in `wp-content/uploads/vcf-cards/` and are **publicly accessible by URL**, because the cards are meant to be shared.

## Security

- AJAX requests require a valid nonce and the `edit_post` capability on the employee.
- `post_id` is cast with `absint()` and must belong to an `employees` post.
- Text values are escaped for vCard (line breaks, `;`, `,`, `\`). URLs are validated with `esc_url_raw()` and stripped of control characters, which prevents injection of fake vCard properties.
- File names are sanitized (`sanitize_file_name()`) and include the post ID and language.

## Changelog

### 1.0.2
- Security: nonce and capability checks on the AJAX handler.
- Security: validation of `post_id`, escaping of all vCard values, sanitized file names.
- Fix: the cron job called the AJAX handler directly and could not work. Logic moved to a shared function.
- Fix: no fatal error if ACF or Polylang is inactive.
- Fix: file URL built from the uploads base URL instead of replacing `ABSPATH`.
- Fix: no trailing "/" in the title when a translation is missing.
- Change: file names now include the post ID, so cards must be regenerated.
- Change: HTML removed from the vCard note, address formatted as a structured `ADR` field.

### 1.0.0
- Initial version delivered to the client.

## Known limitations / next steps

- Scripts and styles load on every admin page. They should be limited to the employee edit screen.
- Functions are global and prefixed loosely. A namespace or class structure would be safer.
- Strings are not yet translatable (`__()`).
- The cron event is not cleared on deactivation.

## License

GPL v2 or later: https://www.gnu.org/licenses/gpl-2.0.html
