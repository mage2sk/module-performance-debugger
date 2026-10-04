# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.3] - 2026-10-04

### Fixed
- A browser with a profiling session now gets uncached storefront pages, so the toolbar and run capture work on pages that are already in the Full Page Cache. Other visitors still get cached pages.
- The storefront toolbar launcher is now a keyboard-accessible button with a 44px tap target and aria-expanded state; opening the panel moves focus to its close button and closing it (also with Escape) returns focus to the launcher. Visible focus outlines were added.
- The bolt icon in the toolbar header is visible again.
- The toolbar panel no longer overflows the left edge of the screen on themes that show a page scrollbar (seen on Luma at 375px and 768px).
- "Allowed IP Addresses" now rejects entries that are not a valid IPv4 or IPv6 address or `*` when the configuration is saved.
- The profiling session token is removed from the address bar after the session cookie is stored.
