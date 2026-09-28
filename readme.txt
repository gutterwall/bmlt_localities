=== BMLT Localities by Service Body ===
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv2 or later

Install the ZIP in Plugins > Add New > Upload Plugin. Activate it, then open Settings > BMLT Localities. Enter your HTTPS BMLT root server URL (for example https://example.org/main_server), save, select one or more service bodies, and save again.

Add [bmlt_localities] to a page. To override the admin selection on one page, use [bmlt_localities services="12,34"]. The numbers are the IDs displayed beside the service body names in settings.

Each Area and state appears on one row, with its unique meeting localities in a comma-separated list. The Area and phone number cell spans its state rows. Area groups alternate light blue and white; a thick divider marks each new Area, with no lines between its state rows. The Area name links to its BMLT service body website URL when available. The phone number comes from the service body helpline field and links using tel: when it contains a dialable number; missing fields remain plain text. Virtual meetings lacking a locality or state are omitted. This is a meeting-derived list, not an official boundary or coverage-area definition. Parent and child bodies are listed separately, without automatically assigning child meetings to the parent. Results are cached for one hour. The public BMLT Semantic API is used; no credentials are needed.
