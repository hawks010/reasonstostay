<?php
/**
 * RTS Site Manual (Admin)
 *
 * - Adds a top-level "Site Manual" menu item.
 * - Forces its position directly under the top-level "Subscribers" menu item.
 * - Provides an in-dashboard editor so the manual can be updated without redeploying theme files.
 * - Self-heals legacy "\uXXXX" escaped sequences (renders as u203a etc) by decoding and resaving.
 *
 * NOTE: This file is intentionally self-contained to avoid touching other theme systems.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RTS_SITE_MANUAL_OPTION', 'rts_site_manual_html');

/**
 * Register admin menu (top-level).
 * Priority 60 (default) - we let the filter below handle the exact positioning.
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Reasons to Stay - Site Manual',
        'Site Manual',
        'manage_options',
        'rts-site-manual',
        'rts_render_site_manual_page',
        'dashicons-book-alt',
        60
    );
});

/**
 * Force menu position: directly under top-level Subscribers.
 */
add_filter('menu_order', function ($menu_order) {
    if (!is_array($menu_order)) {
        return $menu_order;
    }

    // CORRECTED: The slug in the menu array is just the ID, not the full URL.
    $manual_slug = 'rts-site-manual'; 

    // Candidates for the "Subscribers" menu item to sit underneath.
    $subs_candidates = [
        'rts-subscribers-dashboard',       // Top level page slug
        'edit.php?post_type=rts_subscriber', // Standard CPT slug
        'rts_subscriber',                  // Fallback CPT slug
        'rts-subscribers',
        'admin.php?page=rts-subscribers-dashboard', // Legacy check
    ];

    $subs_index = false;
    
    // 1. Try exact matches
    foreach ($subs_candidates as $cand) {
        $idx = array_search($cand, $menu_order, true);
        if ($idx !== false) { 
            $subs_index = $idx; 
            break; 
        }
    }

    // 2. Fallback: fuzzy match for anything looking like a subscriber menu
    if ($subs_index === false) {
        foreach ($menu_order as $i => $slug) {
            if (is_string($slug) && (strpos($slug, 'rts-subscrib') !== false || strpos($slug, 'subscriber') !== false)) { 
                $subs_index = $i; 
                break; 
            }
        }
    }

    // Remove our manual from its current random spot in the list
    $menu_order = array_values(array_filter($menu_order, function ($slug) use ($manual_slug) {
        return $slug !== $manual_slug;
    }));
    
    // If we absolutely can't find Subscribers, place it at position 3 (after Dashboard/SiteKit)
    if ($subs_index === false) {
        array_splice($menu_order, 2, 0, [$manual_slug]);
        return $menu_order;
    }

    // Insert right after Subscribers
    array_splice($menu_order, $subs_index + 1, 0, [$manual_slug]);

    return $menu_order;
}, 999);

add_filter('custom_menu_order', '__return_true');

/**
 * Enqueue Special Elite font for the manual page only.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_rts-site-manual') {
        return;
    }

    // Brand font used by the guide.
    wp_enqueue_style(
        'rts-site-manual-fonts',
        'https://fonts.googleapis.com/css2?family=Special+Elite&display=swap',
        [],
        null
    );
});

/**
 * Decode unicode escape sequences like "\u203a" and surrogate pairs like "\ud83d\udcca".
 * Uses JSON decoding trick to interpret escapes safely.
 */
function rts_site_manual_decode_unicode_escapes($string) {
    if (!is_string($string) || $string === '') {
        return $string;
    }

    // Fast path: nothing to decode.
    if (strpos($string, '\\u') === false && strpos($string, '\\U') === false) {
        return $string;
    }

    // json_decode expects a JSON string: wrap and escape existing backslashes/quotes.
    $json = '"' . str_replace(
        ["\\", "\"", "\r"],
        ["\\\\", "\\\"", ""],
        $string
    ) . '"';

    $decoded = json_decode($json, true);

    if (is_string($decoded) && $decoded !== '') {
        return $decoded;
    }

    return $string;
}

/**
 * Build a dynamic embed snippet (always correct even if the theme folder changes).
 */
function rts_site_manual_get_embed_snippet() {
    $api = home_url('/wp-json/rts/v1/embed/random');
    $js  = get_stylesheet_directory_uri() . '/embeds/assets/rts-widget.js';

    return '<div id="rts-widget" data-api="' . esc_url($api) . '"></div>' . "\n"
         . '<script src="' . esc_url($js) . '"></script>';
}

/**
 * System reference block (keeps key facts accurate even if the manual body text is edited).
 */
function rts_site_manual_get_system_reference_html() {
    $feedback_endpoint = home_url('/wp-json/rts/v1/feedback/submit');
    $embed_api         = home_url('/wp-json/rts/v1/embed/random');

    $snippet = rts_site_manual_get_embed_snippet();

    ob_start(); ?>
    <div style="max-width:100%;margin:0 0 18px 0;">
        <div style="background:#0f172a;border:1px solid #334155;border-radius:18px;padding:35px;color:#f8fafc;">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
                <div style="font-weight:900;color:#FCA311;letter-spacing:.4px;text-transform:uppercase;">System Reference (kept up to date)</div>
                <div style="opacity:.9;font-size:13px;">These notes reflect the current live system behaviour.</div>
            </div>

            <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="background:#1e293b;border:1px solid #334155;border-radius:14px;padding:14px;">
                    <div style="font-weight:800;color:#fff;margin-bottom:6px;">Letter moderation flow</div>
                    <div style="color:#94a3b8;font-size:13px;line-height:1.55;">
                        Most letters are processed automatically: safety scan, moderation rules, tone/feeling tagging, then publish to the live pool.
                        Manual review is only needed for letters that are flagged by the system, or letters that have been downvoted enough to trigger review.
                    </div>
                </div>

                <div style="background:#1e293b;border:1px solid #334155;border-radius:14px;padding:14px;">
                    <div style="font-weight:800;color:#fff;margin-bottom:6px;">Feedback and reports</div>
                    <div style="color:#94a3b8;font-size:13px;line-height:1.55;">
                        Visitor feedback submits to <code style="background:#020617;color:#f8fafc;padding:2px 6px;border-radius:6px;"><?php echo esc_html($feedback_endpoint); ?></code>
                        and is stored as <strong>Feedback</strong> entries in the admin area (under Letters).
                        This data helps the system learn and improve over time.
                    </div>
                </div>

                <div style="grid-column:1 / -1;background:#020617;border:1px solid #334155;border-radius:14px;padding:14px;">
                    <div style="font-weight:800;color:#fff;margin-bottom:6px;">Embed widget (Hope Widget)</div>
                    <div style="color:#94a3b8;font-size:13px;line-height:1.55;margin-bottom:10px;">
                        The widget is not "daily". It continuously rotates letters and lets visitors press “Read Another Letter” just like the main site.
                        Widget API: <code style="background:#0b1220;color:#f8fafc;padding:2px 6px;border-radius:6px;"><?php echo esc_html($embed_api); ?></code>
                    </div>
                    <div style="font-weight:800;color:#FCA311;margin-bottom:8px;">Copy/paste snippet</div>
                    <textarea readonly style="width:100%;min-height:84px;resize:vertical;background:#0b1220;color:#f8fafc;border:1px solid #334155;border-radius:12px;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:12px;"><?php echo esc_textarea($snippet); ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Default manual HTML (your provided block), stored only if the option is empty.
 * Kept as-is to preserve your interactive manual content and styling.
 */
function rts_site_manual_get_default_html() {
    return <<<'RTSMANUAL'
<div id="rts-admin-guide">
<div class="rts-guide-card">
<div class="rts-guide-header">
<img class="rts-brand" src="https://reasonstostay.inkfire.co.uk/wp-content/uploads/2026/01/Screenshot-2026-01-27-at-00.30.21.png" alt="Reasons to Stay" />
<p class="rts-h1">Reasons to Stay: System Admin Guide</p>
<p class="rts-sub">Your complete manual for managing letters, subscribers, newsletters, and the moderation system.</p>
</div>

<div class="rts-guide-content">

<div class="rts-admin-bar">
<div class="rts-admin-bar-title">⚡ Quick Actions</div>
<a href="/wp-admin/edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard" class="rts-btn rts-btn-primary">📊 Subscriber Dashboard</a>
<a href="/wp-admin/edit.php?post_type=letter" class="rts-btn rts-btn-outline">✉️ Manage Letters</a>
<a href="/wp-admin/edit.php?post_type=rts_newsletter" class="rts-btn rts-btn-outline">📰 Create Newsletter</a>
<a href="/wp-admin/edit.php?post_type=rts_subscriber" class="rts-btn rts-btn-outline">👥 View Subscribers</a>
</div>

<div class="rts-grid-2">
<div class="rts-guide-section">
<h2>0. How the System Works (The Basics)</h2>
<p class="rts-muted">The platform is designed to run largely on autopilot, collecting letters and delivering them safely to people who need them.</p>

<div class="rts-grid-2-tight">
<div class="rts-mini">
<div class="rts-mini-title">Public Submits</div>
<div class="rts-mini-text">People write anonymous letters of hope via the website form.</div>
</div>
<div class="rts-mini">
<div class="rts-mini-title">Smart Safety Check</div>
<div class="rts-mini-text">Our "Smart Engine" scans every letter instantly for harmful language.</div>
</div>
<div class="rts-mini">
<div class="rts-mini-title">You Approve</div>
<div class="rts-mini-text">Safe letters sit in "Pending". You review them, edit if needed, and click Publish.</div>
</div>
<div class="rts-mini">
<div class="rts-mini-title">System Delivers</div>
<div class="rts-mini-text">Published letters are shown randomly to visitors and emailed to subscribers.</div>
</div>
</div>

<div class="rts-highlight-box">
<h3>Key Concept: The "Pool"</h3>
<p>Think of your published letters as a big pool. When a user clicks "Next Letter" or receives a daily email, the system randomly picks one from this pool. The more letters you publish, the better the experience.</p>
</div>
</div>

<div class="rts-guide-section">
<h2>Where to Find Everything</h2>
<p class="rts-muted">The admin menu on the left is your control center. Here are the main areas you'll use:</p>

<ul class="rts-feature-list">
<li><strong>Letters:</strong> Where all user-submitted stories live. This is your moderation queue.</li>
<li><strong>Subscribers:</strong> A dedicated section for managing your email audience.</li>
<li><strong>Newsletters:</strong> Create and schedule beautiful email updates to your subscribers.</li>
<li><strong>RTS Settings:</strong> Technical settings for email delivery and the automated safety system.</li>
</ul>

<div class="rts-callout">
<div class="rts-callout-title">The "Self-Learning" System</div>
<div class="rts-callout-text">
The moderation system learns over time. If you approve a letter the system flagged, or reject one it thought was safe, it gets smarter at predicting future submissions.
</div>
</div>
</div>
</div>


<div class="rts-grid-3">
<div class="rts-guide-section">
<h2>1. Moderating Letters</h2>
<p class="rts-muted">Your most important daily task is reviewing new letters.</p>

<ul class="rts-feature-list">
<li>Go to <strong>Letters</strong>. Look for "Pending" items.</li>
<li><strong>Red Flag?</strong> The system will mark dangerous letters as "Flagged". Check these carefully or delete them.</li>
<li><strong>Edit:</strong> You can fix typos or remove identifying info (names, places) to keep it anonymous.</li>
<li><strong>Publish:</strong> Click "Publish" to add it to the live pool.</li>
</ul>

<div class="rts-highlight-box">
<h3>Safety First</h3>
<p>If a letter contains specific suicide plans or self-harm details, do not publish it. The system is for hope and support, not crisis management.</p>
</div>
</div>

<div class="rts-guide-section">
<h2>2. Sending Newsletters</h2>
<p class="rts-muted">Send updates to your subscribers using the new visual editor.</p>

<ul class="rts-feature-list">
<li>Go to <strong>Newsletters → Add New</strong>.</li>
<li>Write your content in the main box.</li>
<li><strong>Magic Buttons:</strong> Use the "Insert Random Letter" button in the sidebar to instantly add a touching story to your email.</li>
<li><strong>Schedule:</strong> Pick a date and time in the sidebar to send it later, or send immediately.</li>
</ul>

<div class="rts-callout">
<div class="rts-callout-title">Smart Styling</div>
<div class="rts-callout-text">You don't need to design anything. The system automatically wraps your text in the "Reasons to Stay" branded letter format with your logo and footer.</div>
</div>
</div>

<div class="rts-guide-section">
<h2>3. Managing Subscribers</h2>
<p class="rts-muted">See who is reading and manage their preferences.</p>

<ul class="rts-feature-list">
<li><strong>Dashboard:</strong> The "Subscriber Dashboard" gives you a quick health check—new signups, total active users, and open rates.</li>
<li><strong>List View:</strong> You can search for specific people or filter by "Active" vs "Unsubscribed".</li>
<li><strong>Adding People:</strong> You can manually add a subscriber if you have their permission via the "Add Subscriber" button.</li>
</ul>

<div class="rts-highlight-box">
<h3>Privacy</h3>
<p>Subscribers can unsubscribe instantly via a link in every email. You never need to do this manually.</p>
</div>
</div>
</div>


<div class="rts-guide-section" style="margin-bottom:30px;">
<h2>4. The Embed Widget (Sharing Hope)</h2>
<p class="rts-muted">Other websites can now display your letters automatically using our new "Hope Widget". This helps spread the message further.</p>

<div class="rts-grid-2">
<div>
<h3 style="font-size:16px;font-weight:800;color:#FCA311;margin:20px 0 10px 0;">How it Works</h3>
<p class="rts-muted">When another site adds our code, a small card appears on their page. It shows a random letter from your collection. Readers can click "Next" to read another one right there on the external site.</p>

<div class="rts-highlight-box">
<h3>The Code Snippet</h3>
<p>If someone asks "How do I add this?", give them this code:</p>
<code style="background:#020617; color:#f8fafc; padding:10px; display:block; border-radius:6px; font-size:12px;">&lt;div id="rts-daily-letter"&gt;&lt;/div&gt;<br>&lt;script src="https://reasonstostay.com/widget/embed.js" async&gt;&lt;/script&gt;</code>
</div>
</div>

<div>
<h3 style="font-size:16px;font-weight:800;color:#FCA311;margin:20px 0 10px 0;">Why use it?</h3>
<ul class="rts-feature-list">
<li><strong>Reach:</strong> People who never visit your site will still see the letters.</li>
<li><strong>Branding:</strong> The widget is locked to your design (Dark mode/Gold) and cannot be changed by the other site.</li>
<li><strong>Traffic:</strong> It includes a "Read More" link back to reasonstostay.com.</li>
</ul>
</div>
</div>
</div>


<div class="rts-grid-2">
<div class="rts-guide-section">
<h2>5. Editing Website Text (Elementor)</h2>
<p class="rts-muted">The main pages (Home, About) are built with Elementor. It's a visual "drag and drop" editor.</p>

<div class="rts-steps">
<div class="rts-stepline"><span class="rts-stepnum">1</span>Visit the page you want to change (e.g., Homepage) while logged in.</div>
<div class="rts-stepline"><span class="rts-stepnum">2</span>Click <strong>Edit with Elementor</strong> in the top black bar.</div>
<div class="rts-stepline"><span class="rts-stepnum">3</span><strong>Click</strong> on any text to change it. You can type right on the screen.</div>
<div class="rts-stepline"><span class="rts-stepnum">4</span>Use the <strong>left sidebar</strong> to change fonts, colors, or alignment if needed.</div>
<div class="rts-stepline"><span class="rts-stepnum">5</span>Click the green <strong>Update</strong> button at the bottom left to save.</div>
</div>

<div class="rts-callout">
<div class="rts-callout-title">A Friendly Note on "Shortcodes"</div>
<div class="rts-callout-text">If you spot a grey block with code like <code>[rts_letter_viewer]</code>, please leave it just as it is. That's the special code powering the letter system, and it works best when untouched.</div>
</div>
</div>

<div class="rts-guide-section">
<h2>6. The Onboarding System</h2>
<p class="rts-muted">When a new visitor arrives, they see a "How are you feeling?" popup. This isn't just for show—it personalizes their experience.</p>

<div class="rts-grid-2-tight">
<div class="rts-mini">
<div class="rts-mini-title">Tags Match Feelings</div>
<div class="rts-mini-text">If they click "Lonely", the system prioritizes letters tagged with "Comfort" or "Connection".</div>
</div>
<div class="rts-mini">
<div class="rts-mini-title">Skip Option</div>
<div class="rts-mini-text">Users can skip this if they just want random letters.</div>
</div>
<div class="rts-mini">
<div class="rts-mini-title">Remembering</div>
<div class="rts-mini-text">The site remembers their choice for their visit so they don't have to choose every time.</div>
</div>
</div>

<div class="rts-highlight-box">
<h3>Managing Tags</h3>
<p>When editing a letter, check the "Letter Tone" and "Feeling" boxes on the right side. This helps the onboarder match the right letter to the right person.</p>
</div>
</div>
</div>

<div class="rts-guide-section" style="margin-top:30px;">
<h2>7. Frequently Asked Questions</h2>
<p class="rts-muted">Common questions you might run into while managing the site.</p>

<div class="rts-faq">
<div class="rts-faq-item">
<div class="rts-faq-q">Can I reply to a letter writer?</div>
<div class="rts-faq-a">No. The system is designed to be completely anonymous for safety. We do not store email addresses for letter submissions, so there is no way to contact the author.</div>
</div>
<div class="rts-faq-item">
<div class="rts-faq-q">I accidentally published a letter I meant to delete. Can I fix it?</div>
<div class="rts-faq-a">Yes! Just go to <strong>Letters</strong>, find the letter, hover over it, and click "Trash" or change its status back to "Draft". It will instantly disappear from the live site.</div>
</div>
<div class="rts-faq-item">
<div class="rts-faq-q">Can I change the website colors (Gold/Dark Blue)?</div>
<div class="rts-faq-a">These colors are hard-coded into the theme to ensure brand consistency. If you need a design change, please contact support.</div>
</div>
<div class="rts-faq-item">
<div class="rts-faq-q">Why does the "New Subscribers" number reset?</div>
<div class="rts-faq-a">The dashboard shows "New Signups (This Week)". This counter resets every Monday so you can track weekly growth. Your "Total Active Subscribers" number never resets.</div>
</div>
</div>
</div>

<div class="rts-guide-section" style="margin-top:30px; border-top:3px solid #FCA311;">
<h2 style="color:#f8fafc; border:none;">Need Technical Help?</h2>
<p class="rts-muted">If something isn't working right or you need a hand with the website, the Inkfire team is here to help.</p>

<div class="rts-admin-bar" style="background:#0f172a; border-color:#334155; margin-bottom:0;">
<div class="rts-admin-bar-title" style="color:#fff;">Inkfire Support:</div>
<a href="mailto:sonny@inkfire.co.uk" class="rts-btn rts-btn-primary">✉️ Email Sonny (Support)</a>
<a href="https://inkfire.co.uk/knowledge-base" target="_blank" class="rts-btn rts-btn-outline">📚 Inkfire Knowledge Base</a>
<a href="https://inkfire.co.uk" target="_blank" class="rts-btn rts-btn-outline">🌐 Visit Inkfire.co.uk</a>
</div>
</div>

</div>

<div class="rts-guide-footer">
Reasons to Stay Admin System © 2026 • Built for Ben West
</div>

</div>
</div>

<style>
/* SCOPED CSS for RTS Admin Guide */
#rts-admin-guide {
box-sizing: border-box;
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
color: #f8fafc;
background: transparent;
width: 100%;
margin: 0;
padding: 0;
}

#rts-admin-guide * { box-sizing: border-box; }

#rts-admin-guide a {
color: #FCA311;
text-decoration: none;
font-weight: 700;
transition: color 0.2s;
}
#rts-admin-guide a:hover { color: #fff; }

/* Card Container */
#rts-admin-guide .rts-guide-card {
background: #0f172a; /* Slate 900 */
border-radius: 20px;
box-shadow: 0 4px 20px rgba(0,0,0,0.2);
overflow: hidden;
border: 1px solid #334155;
margin: 0;
width: 100%;
max-width: 100%;
}

/* Header */
#rts-admin-guide .rts-guide-header {
padding: 40px;
background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
color: #ffffff;
border-bottom: 3px solid #FCA311;
text-align: center;
}
#rts-admin-guide .rts-brand {
display: block;
width: auto;
height: 60px;
margin: 0 auto 20px auto;
}
#rts-admin-guide .rts-h1 {
margin: 0;
font-size: 28px;
font-weight: 800;
letter-spacing: -0.5px;
color: #fef3c7; /* Cream/Light Yellow */
line-height: 1.2;
font-family: "Special Elite", monospace; /* Brand font */
}
#rts-admin-guide .rts-sub {
margin: 10px 0 0 0;
font-size: 16px;
opacity: 0.8;
font-weight: 400;
line-height: 1.5;
color: #94a3b8;
}

/* Content Area */
#rts-admin-guide .rts-guide-content { padding: 40px; }

/* Admin Quick Links Bar */
#rts-admin-guide .rts-admin-bar {
display: flex;
gap: 15px;
flex-wrap: wrap;
padding: 25px;
background: #1e293b;
border-radius: 12px;
border: 1px solid #334155;
margin-bottom: 40px;
align-items: center;
width: 100%;
}
#rts-admin-guide .rts-admin-bar-title {
font-weight: 800;
color: #FCA311;
font-size: 1.1rem;
margin-right: 15px;
text-transform: uppercase;
letter-spacing: 0.5px;
}

/* Buttons */
#rts-admin-guide .rts-btn {
display: inline-flex;
align-items: center;
justify-content: center;
padding: 10px 20px;
border-radius: 8px;
font-weight: 700;
font-size: 13px;
text-decoration: none !important;
transition: all 0.2s;
cursor: pointer;
line-height: 1.2;
}
#rts-admin-guide .rts-btn-primary {
background: #FCA311;
color: #000 !important;
border: 1px solid #FCA311;
}
#rts-admin-guide .rts-btn-primary:hover {
background: #e5940e;
transform: translateY(-1px);
}
#rts-admin-guide .rts-btn-outline {
background: transparent;
color: #FCA311 !important;
border: 1px solid #FCA311;
}
#rts-admin-guide .rts-btn-outline:hover {
background: rgba(252, 163, 17, 0.1);
transform: translateY(-1px);
}

/* Grids */
#rts-admin-guide .rts-grid-2 {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 30px;
width: 100%;
margin-bottom: 30px;
}
#rts-admin-guide .rts-grid-3 {
display: grid;
grid-template-columns: 1fr 1fr 1fr;
gap: 30px;
width: 100%;
margin-bottom: 30px;
}
#rts-admin-guide .rts-grid-2-tight {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 12px;
width: 100%;
}

/* Sections */
#rts-admin-guide .rts-guide-section {
padding: 30px;
border-radius: 16px;
border: 1px solid #334155;
background: #1e293b;
height: 100%;
width: 100%;
}
#rts-admin-guide .rts-guide-section h2 {
margin: 0 0 20px 0;
font-size: 20px;
color: #FCA311;
font-weight: 800;
border-bottom: 1px solid #334155;
padding-bottom: 15px;
line-height: 1.3;
}

#rts-admin-guide .rts-muted {
color: #94a3b8;
font-size: 15px;
line-height: 1.6;
margin-bottom: 15px;
}

/* Lists */
#rts-admin-guide ul.rts-feature-list {
padding: 0;
margin: 0;
list-style: none;
}
#rts-admin-guide ul.rts-feature-list li {
position: relative;
padding-left: 25px;
margin-bottom: 12px;
font-size: 14px;
line-height: 1.5;
color: #f8fafc;
}
#rts-admin-guide ul.rts-feature-list li::before {
content: '›';
position: absolute;
left: 0;
top: 0;
color: #FCA311;
font-weight: bold;
font-size: 18px;
line-height: 1;
}

/* Highlight Box */
#rts-admin-guide .rts-highlight-box {
background: rgba(252, 163, 17, 0.1);
border-left: 4px solid #FCA311;
padding: 20px;
margin-top: 20px;
border-radius: 4px;
}
#rts-admin-guide .rts-highlight-box h3 {
margin: 0 0 10px 0;
color: #FCA311;
font-size: 16px;
font-weight: 800;
}
#rts-admin-guide .rts-highlight-box p {
margin: 0;
font-size: 14px;
color: #e2e8f0;
line-height: 1.5;
}

/* Callouts */
#rts-admin-guide .rts-callout {
margin-top: 18px;
background: #020617;
border: 1px solid #334155;
padding: 16px;
border-radius: 10px;
}
#rts-admin-guide .rts-callout-title {
font-weight: 800;
color: #fff;
margin-bottom: 6px;
font-size: 14px;
}
#rts-admin-guide .rts-callout-text {
color: #94a3b8;
font-size: 13px;
line-height: 1.5;
}

/* Mini cards */
#rts-admin-guide .rts-mini {
border: 1px solid #334155;
border-radius: 8px;
padding: 14px;
background: #0f172a;
}
#rts-admin-guide .rts-mini-title {
font-weight: 800;
color: #fff;
margin-bottom: 6px;
font-size: 13px;
}
#rts-admin-guide .rts-mini-text {
color: #94a3b8;
font-size: 12px;
line-height: 1.4;
}

/* Steps */
#rts-admin-guide .rts-steps { margin-top: 12px; }
#rts-admin-guide .rts-stepline {
display: flex;
gap: 12px;
align-items: flex-start;
padding: 10px 0;
border-bottom: 1px dashed #334155;
font-size: 14px;
color: #e2e8f0;
}
#rts-admin-guide .rts-stepline:last-child { border-bottom: none; }
#rts-admin-guide .rts-stepnum {
flex: 0 0 auto;
width: 24px;
height: 24px;
border-radius: 50%;
background: #FCA311;
color: #000;
display: inline-flex;
align-items: center;
justify-content: center;
font-weight: 800;
font-size: 12px;
margin-top: -2px;
}

/* FAQ Styles */
#rts-admin-guide .rts-faq { margin-top: 20px; }
#rts-admin-guide .rts-faq-item {
margin-bottom: 15px;
border: 1px solid #334155;
border-radius: 8px;
overflow: hidden;
}
#rts-admin-guide .rts-faq-q {
background: #0f172a;
padding: 15px 20px;
font-weight: 700;
color: #fff;
cursor: pointer;
font-size: 14px;
}
#rts-admin-guide .rts-faq-a {
padding: 15px 20px;
background: #1e293b;
color: #94a3b8;
font-size: 14px;
line-height: 1.5;
border-top: 1px solid #334155;
}

/* Footer */
#rts-admin-guide .rts-guide-footer {
padding: 20px 40px;
background: #0f172a;
border-top: 1px solid #334155;
font-size: 12px;
color: #64748b;
text-align: right;
}

/* Mobile */
@media (max-width: 980px){
#rts-admin-guide .rts-grid-3 { grid-template-columns: 1fr; }
#rts-admin-guide .rts-grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 768px){
#rts-admin-guide .rts-guide-header,
#rts-admin-guide .rts-guide-content { padding: 20px; }
#rts-admin-guide .rts-admin-bar { flex-direction: column; align-items: stretch; }
#rts-admin-guide .rts-grid-2-tight { grid-template-columns: 1fr; }
}
</style>
RTSMANUAL;
}

/**
 * Save handler for the manual editor.
 */
add_action('admin_post_rts_save_site_manual', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    check_admin_referer('rts_site_manual_save', 'rts_site_manual_nonce');

    $raw = isset($_POST['rts_site_manual_html']) ? wp_unslash($_POST['rts_site_manual_html']) : '';
    $raw = (string) $raw;

    // Decode any \uXXXX sequences so the manual renders correctly.
    $clean = rts_site_manual_decode_unicode_escapes($raw);

    update_option(RTS_SITE_MANUAL_OPTION, $clean, false);

    wp_safe_redirect(admin_url('admin.php?page=rts-site-manual&updated=1'));
    exit;
});

/**
 * Render admin page (view + edit modes).
 */
function rts_render_site_manual_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    $is_edit = isset($_GET['edit']) && $_GET['edit'] === '1';

    $manual_html = get_option(RTS_SITE_MANUAL_OPTION, '');

    // Seed from bundled legacy content if option is empty (keeps behaviour consistent on first install).
    if (!is_string($manual_html)) {
        $manual_html = '';
    }
    if ($manual_html === '') {
        // Attempt to load previous embedded manual content from this file (if you pasted it here earlier).
        $manual_html = rts_site_manual_get_default_html();
        if ($manual_html !== '') {
            update_option(RTS_SITE_MANUAL_OPTION, $manual_html, false);
        }
    }

    // Self-heal legacy unicode escapes on first view.
    if (strpos($manual_html, '\\u') !== false || strpos($manual_html, '\\U') !== false) {
        $decoded = rts_site_manual_decode_unicode_escapes($manual_html);
        if ($decoded !== $manual_html) {
            $manual_html = $decoded;
            update_option(RTS_SITE_MANUAL_OPTION, $manual_html, false);
        }
    }

    $updated = isset($_GET['updated']) && $_GET['updated'] === '1';

    ?>
    <div class="wrap" style="max-width: 100%;">
        <style>
            /* Manual-only UI tweaks (scoped to this page) */
            #rts-site-manual-shell{position:relative;}
            #rts-site-manual-fab{position:absolute;top:18px;right:22px;z-index:50;display:flex;gap:10px;align-items:center;flex-wrap:nowrap;}
            #rts-site-manual-fab .button{box-shadow:0 8px 20px rgba(0,0,0,.18);border-radius:12px;}
            #rts-site-manual-toast{background:#d1fae5;color:#065f46;border:1px solid rgba(6,95,70,.25);padding:8px 12px;border-radius:12px;font-weight:800;}
        </style>

        <div id="rts-site-manual-shell">
            <div id="rts-site-manual-fab">
                <?php if ($updated): ?>
                    <div id="rts-site-manual-toast">Saved</div>
                <?php endif; ?>

                <?php if (!$is_edit): ?>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=rts-site-manual&edit=1')); ?>">Edit Manual</a>
                <?php else: ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rts-site-manual')); ?>">View Manual</a>
                <?php endif; ?>
            </div>

        <?php if ($is_edit): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('rts_site_manual_save', 'rts_site_manual_nonce'); ?>
                <input type="hidden" name="action" value="rts_save_site_manual" />

                <div style="background:#fff;border:1px solid #cbd5e1;border-radius:14px;overflow:hidden;">
                    <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px 12px;border-bottom:1px solid #e2e8f0;">
                        <div style="font-weight:800;">Edit HTML</div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="button" class="button" id="rts-manual-copy">Copy All</button>
                            <button type="submit" class="button button-primary">Save Manual</button>
                        </div>
                    </div>

                    <textarea id="rts-site-manual-editor" name="rts_site_manual_html" style="width:100%;min-height:70vh;padding:14px;border:0;outline:none;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:12px;line-height:1.5;"><?php
                        echo esc_textarea($manual_html);
                    ?></textarea>
                </div>

                <p style="margin-top:10px;color:#475569;">
                    Tip: If you paste content that includes sequences like <code>\u203a</code>, saving will automatically convert them into proper characters.
                </p>
            </form>

            <script>
            (function(){
                var btn = document.getElementById('rts-manual-copy');
                var ta  = document.getElementById('rts-site-manual-editor');
                if(!btn || !ta) return;

                btn.addEventListener('click', async function(){
                    try{
                        await navigator.clipboard.writeText(ta.value);
                        btn.textContent = 'Copied!';
                        setTimeout(function(){ btn.textContent = 'Copy All'; }, 1200);
                    }catch(e){
                        ta.focus();
                        ta.select();
                        document.execCommand('copy');
                        btn.textContent = 'Copied!';
                        setTimeout(function(){ btn.textContent = 'Copy All'; }, 1200);
                    }
                });
            })();
            </script>
        <?php else: ?>
            <div id="rts-site-manual-render">
                <?php
                // Render the stored manual HTML as-is (admin-only).
                echo $manual_html;
                ?>
            </div>

            <?php
                // Place System Reference under the manual.
                echo rts_site_manual_get_system_reference_html();
            ?>
        <?php endif; ?>

        </div><!-- /#rts-site-manual-shell -->
    </div>
    <?php
}
