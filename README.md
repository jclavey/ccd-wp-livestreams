# CCD Livestreams

A WordPress plugin for a church livestreams page. Each service stream is an entry (date, time, title and YouTube link). The page shows the streams from the last two weeks as a grid of Kadence Blocks, two per row, each with its YouTube thumbnail and a linked caption such as **22.11.26 – 11:15 Morning Service**.

New streams are suggested from your YouTube playlists, so the weekly update is usually: open the page, check the details, click **Add selected**.

## Requirements

- WordPress 6.0+ and PHP 7.4+
- [Kadence Blocks](https://wordpress.org/plugins/kadence-blocks/), which provides the row layout, column and image blocks. WordPress 6.5+ won't activate this plugin until Kadence Blocks is active.

## Install

Copy this folder to `wp-content/plugins/ccd-livestreams/` (or upload it as a zip) and activate **CCD Livestreams**.

Then go to **Livestreams > Settings** and add the YouTube playlists your livestreams go into, each with a default title, e.g.

| Playlist link | Default title |
|---|---|
| `https://www.youtube.com/playlist?list=PL…` | Morning Service |
| `https://www.youtube.com/playlist?list=PL…` | Evening Service |

Any video link that includes `&list=` works too.

## Weekly use

Go to **Livestreams** in the admin menu. **Add streams** is one table:

- Each stream in your playlists that hasn't been added yet, dated from two weeks ago up to the coming Sunday, appears as a ticked row. The date and time are read from the YouTube title (e.g. "22 Nov 11.15am"), and the title is the playlist's default title. Change anything that's wrong, and untick anything you don't want.
- The last row is blank, for adding a stream by hand: fill it in and it ticks itself. **+ Add another row** adds more.
- Click **Add selected**.

Rows that can't be saved stay in the table with the reason, e.g. a missing time or a video that's already been added. **Check playlists again** fetches the playlists afresh (they are otherwise cached for 15 minutes).

Streams scheduled for the coming Sunday can be added in advance: an entry only appears on the page from the start of its date.

Below the table, **Recent entries** lists the 10 newest entries. Entries currently shown on the site are marked *On the page*. **Edit** opens the entry, where you can also choose a different image from the Media Library instead of the YouTube thumbnail. **Delete** moves an entry to the Trash, with an **Undo** link. **View all entries** opens the full list.

Only Administrators can manage livestreams.

## Show the livestreams on a page

In the block editor, add the **Livestreams grid** block where the streams should go, e.g. below the page's intro text. The editor shows a placeholder, and the grid appears on the published page.

You can also use the shortcode `[ccd_livestreams]`.

- The page shows entries dated from 13 days ago to today, in the site's timezone. A day's streams appear from the start of that day.
- Newest date first; on the same day, earliest time first. Each date starts a new row, with up to two streams per row, so a Sunday with only one service has a row of its own with the stream in the left half. The columns stack on phones.
- The image is the YouTube thumbnail (`maxresdefault` when YouTube has one, otherwise `hqdefault`, cropped to 16:9), or the entry's own image if one was chosen. The image and caption link to `https://www.youtube.com/live/<id>` in a new tab. The image's alt text is the caption.
- The caption is `d.m.y – g:i <title>`, with the time in 12-hour form without am/pm, e.g. **22.11.26 – 6:15 Evening Service**.
- When nothing is in the last two weeks, the message from **Settings > When there are no recent livestreams** is shown instead.

## How the suggestions work

Service livestreams are usually **unlisted**, and YouTube's RSS feeds leave unlisted videos out, so the plugin reads the public playlist page (`youtube.com/playlist?list=…`) and the `ytInitialData` it contains. No API key is needed. For each video it uses:

- the title, for the date and time ("22 Nov 11.15am", "29 December 6.15pm", "3rd Jan 2027 10:30 am" all work);
- "Scheduled for …" for the year of an upcoming stream;
- "Streamed 8 days ago" / "Streamed 1 yr ago" for the year of a past one, so last year's "23 Nov" isn't mistaken for this year's.

A video whose title has no readable date is only suggested while it's live. Otherwise add it by hand.

This isn't an official API. If YouTube changes the page, or shows the server a consent or bot-check page, the admin page says the playlist couldn't be read and you can still add streams by hand.

## Data

Entries are a private post type (`ccd_ls_entry`) with no public URLs, and they aren't in the REST API, search or feeds. The post title is the entry's title. Post meta: `_ccd_ls_date` (`Y-m-d`), `_ccd_ls_time` (`H:i`), `_ccd_ls_video_id`, `_ccd_ls_thumb_url` and `_ccd_ls_image_id` (optional override image). There can only be one entry per YouTube video.

Settings are in the `ccd_ls_settings` option.

### Filters

- `ccd_ls_row_attributes` (array, row number): the `kadence/rowlayout` attributes of each row, for gaps and spacing.
- `ccd_ls_entries` (array): the entries about to be shown, in display order.
- `ccd_ls_caption` (string, entry): an entry's caption.
- `ccd_ls_pre_fetch_playlist` and `ccd_ls_pre_find_thumbnail`: return HTML or a URL to skip contacting YouTube, e.g. in tests.

## Tests

On a test site with Kadence Blocks active:

```sh
wp eval-file wp-content/plugins/ccd-livestreams/tests/run-tests.php
```

The tests never contact YouTube. They create their own entries (dated in 2099) and clean up afterwards. Set `CCD_LS_FIXTURE_DIR` to a folder of saved playlist pages (`*.html`) to also check the parser against real YouTube pages.

## Licence

GPL-2.0-or-later.
