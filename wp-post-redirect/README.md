# WP Post Redirect

WordPress plugin to redirect posts, pages and custom post types to an external URL or to other content on the same site.

[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/wp-post-redirect.svg)](https://wordpress.org/plugins/wp-post-redirect/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/wp-post-redirect.svg)](https://wordpress.org/plugins/wp-post-redirect/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/wp-post-redirect.svg)](https://wordpress.org/plugins/wp-post-redirect/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/wp-post-redirect.svg)](https://wordpress.org/plugins/wp-post-redirect/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/wp-post-redirect.svg)](https://wordpress.org/plugins/wp-post-redirect/#reviews)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Set the destination in a metabox on the edit screen and visitors are redirected automatically, with no server configuration.

## Features

- Redirect to an **external URL** or to **internal content** (search posts and pages by title)
- Links to redirected content (menus, archives, feeds) point straight to the destination
- HTTP status **301**, **307** or **308**, set globally or per post
- "Open in new tab" and `rel="nofollow"` options for menu links
- Enable the redirect metabox for any public post type
- **Redirect** column and row highlight in the admin post lists
- Settings page with all active and inactive redirections, and **CSV export**
- Redirects are marked with the `X-Redirect-By: WP Post Redirect` header for easy debugging
- Lightweight: no extra tables, data is stored in post meta

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Installation

From the WordPress dashboard: **Plugins → Add New → search for "WP Post Redirect"**.

After activation, choose the post types to enable in **Settings → WP Post Redirect**, then open a post and fill in the **Redirect** metabox.

## Redirect options

| Option | Description |
| --- | --- |
| Redirect Type | **External URL** or **Internal Content** |
| Destination URL | Address to redirect to (external redirects): `https://…`, `http://…` or a relative path `/…` |
| Search Internal Content | Post or page to redirect to (internal redirects) |
| HTTP Status | Default (from settings), 301, 307 or 308 |
| Open in new tab | Adds `target="_blank"` to menu links pointing to the post |
| Add rel="nofollow" | Adds `rel="nofollow"` to menu links pointing to the post |

To remove a redirect, clear the URL (or remove the internal selection) and update the post.

## Contributing

Bug reports and pull requests are welcome.

## Links

- [Plugin page on WordPress.org](https://wordpress.org/plugins/wp-post-redirect/)
- [Changelog](readme.txt)

## Credits

Copyright © 2013-2026 **Marco Milesi**
[www.marcomilesi.com](https://www.marcomilesi.com)
