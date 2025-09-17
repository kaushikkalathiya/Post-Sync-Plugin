# WordPress Post Sync & Translation Plugin

This plugin syncs posts from a **Host site** to one or more **Target sites** in real time using the WordPress REST API. On the Target site(s), posts are automatically translated into a chosen language (French, Spanish, Hindi) using the **ChatGPT API**.  

---

## 🚀 Install & Setup

### Host Site
1. Upload and activate the plugin on the **Host** site.  
2. In plugin settings:  
   - Add one or more **Target sites** (URL + Secret Key).  
   - Each Target must have a **unique key**.  
3. Save settings.  

### Target Site
1. Upload and activate the **same plugin** on the **Target** site.  
2. In plugin settings:  
   - Add the **API Key** for ChatGPT (used for translation).  
   - Select the **default translation language** (French, Spanish, or Hindi).  
   - Confirm your **domain + key binding** (matches Host configuration).  
3. Save settings. 

---

## ⚙️ Settings Explanation

- **Targets (Host side):** Add multiple Target site URLs with their assigned keys.  
- **Auth Key (Target side):** Used for verifying Host requests.  
- **Translation Language:** Fixed choice per Target: `French | Spanish | Hindi`.  
- **Logs:** Each sync creates an audit log entry with action details (IDs, status, time).  

---

## 🔄 How Real-Time Push Works

When a post is **published or updated** on the Host site, the plugin instantly pushes the post data (title, content, excerpt, categories, tags, featured image) to all configured Target sites. The Target validates the request with HMAC signing, then translates the content **chunk-by-chunk** (1.5–2.5k characters) via ChatGPT, preserving HTML structure. The translated post is then created or updated immediately—no cron jobs.

---

## ⚠️ Limits & Known Issues

- Only supports the **`post`** post type (no custom post types).  
- Only triggers on **publish** and **update** (not delete, draft, or other states).  
- Translation is limited to **French, Spanish, or Hindi**.  
- Very large posts are split into chunks for safe translation—may slightly affect performance.  
- Featured images must be accessible via a public URL.  
- Multiple Targets are supported, but heavy usage may impact performance on shared hosting.  
