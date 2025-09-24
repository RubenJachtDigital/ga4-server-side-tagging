# Auto-Update System Setup Guide

This guide explains how to set up the GitHub-based auto-update system for the GA4 Server-Side Tagging plugin across multiple client sites.

## Overview

The auto-update system allows you to:
- Push updates from your GitHub repository to all client sites
- Manage updates through WordPress admin interface
- Secure configuration with encrypted credentials
- Automated release process via GitHub Actions

## Initial Setup

### 1. Repository Setup

1. **Create GitHub Repository** (if not already done)
   ```bash
   # Initialize repository if needed
   git init
   git add .
   git commit -m "Initial commit"
   git branch -M main
   git remote add origin https://github.com/YOUR_USERNAME/ga4-server-side-tagging.git
   git push -u origin main
   ```

2. **Repository Visibility**:
   - **Public Repository**: No authentication required (but token recommended for better API limits)
   - **Private Repository**: Personal Access Token with "repo" scope is mandatory

### 2. Configure Auto-Updates

#### Option A: WordPress Admin Interface (Recommended)

1. Go to **GA4 Server-Side Tagging > Auto-Updates** in WordPress admin
2. Fill in the configuration:
   - **GitHub Username/Organization**: Your GitHub username or organization
   - **Repository Name**: `ga4-server-side-tagging` (or your repo name)
   - **GitHub Token**:
     - **For Private Repos**: **REQUIRED** - Generate at https://github.com/settings/tokens with "repo" scope
     - **For Public Repos**: Optional but recommended for higher API limits
   - **Enable Auto-Updates**: Check this box

3. Click **Test Configuration** to verify connectivity
4. Click **Save Configuration**

#### Option B: Environment Variables

Create a `.env` file in your plugin directory:

```bash
cp .env.example .env
```

Edit `.env`:
```env
GITHUB_USERNAME=your-github-username
GITHUB_REPO=ga4-server-side-tagging
GITHUB_TOKEN=your-optional-github-token
```

**Important**: Never commit the `.env` file to version control!

### 3. Deploy to Client Sites

1. **Upload Plugin** to each client site
2. **Configure Auto-Updates** on each site using one of the methods above
3. **Test** that updates work by checking the plugins page

## Private Repository Setup

If your repository is private, you **must** provide authentication on each client site.

### Step 1: Create Personal Access Token

1. Go to https://github.com/settings/tokens
2. Click **"Generate new token (classic)"**
3. **Token Settings**:
   - **Note**: `GA4 Plugin Auto-Updates`
   - **Expiration**: Choose appropriate timeframe (recommend: 1 year)
   - **Scopes**: Select `repo` (full repository access)
4. Click **Generate token**
5. **Important**: Copy the token immediately (you can't see it again!)

### Step 2: Configure Each Client Site

**Option A: Via WordPress Admin**
1. Go to **GA4 Server-Side Tagging > Auto-Updates**
2. Enter your GitHub username, repository name, and **token**
3. Test configuration to ensure access works

**Option B: Via .env File**
```env
GITHUB_USERNAME=your-username
GITHUB_REPO=ga4-server-side-tagging
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Step 3: Security Considerations

- **Token Storage**: Tokens are encrypted in WordPress database
- **Token Sharing**: Same token can be used on all 20 client sites
- **Token Rotation**: Update token before expiration across all sites
- **Minimum Permissions**: Token only needs "repo" scope for private repositories

### Alternative: Dual Repository Approach

If managing tokens across 20 sites becomes cumbersome:
1. **Keep development repo private** (your main work)
2. **Create public distribution repo** (for plugin releases only)
3. **Push releases** from private to public repo
4. **Configure clients** to use public repo (no tokens needed)

## Creating Releases

### Automatic Releases (Recommended)

The GitHub Actions workflow automatically creates releases **only when you change the version number** in the main plugin file:

1. **Make Your Code Changes** (add features, fix bugs, etc.)

2. **Update Version Numbers** in `ga4-server-side-tagging.php`:
   ```php
   // Line 14: Plugin header comment
   * Version: 3.0.1

   // Line 31: PHP constant
   define('GA4_SERVER_SIDE_TAGGING_VERSION', '3.0.1');
   ```
   **Important**: Both numbers must match or the workflow will fail.

3. **Commit and Push** to master:
   ```bash
   git add .
   git commit -m "Release v3.0.1 - Bug fixes and improvements"
   git push origin main
   ```

4. **GitHub Actions** will automatically:
   - Detect the version number change
   - Create a release with tag `v3.0.1`
   - Generate changelog from commit messages
   - Create plugin zip file
   - All client sites will see "Update Now" button within 12 hours

### How It Works

- **Only triggers** when `ga4-server-side-tagging.php` is modified
- **Compares** current version with previous commit
- **Creates release** only if version number increased
- **Skips** if version unchanged (prevents unnecessary releases)

### Manual Releases

You can also trigger releases manually:

1. Go to **Actions** tab in your GitHub repository
2. Select **Create Release and Notify Client Sites**
3. Click **Run workflow**
4. Enter version number and release type
5. Click **Run workflow**

## Update Distribution

### How Client Sites Receive Updates

1. **WordPress checks** for plugin updates every 12 hours
2. **Plugin appears** in WordPress admin under Plugins > Updates
3. **Administrators can update** through standard WordPress interface
4. **Auto-updates** can be enabled per-site basis

### Update Timeline

- **Immediate**: Manual update checks
- **12 hours**: Automatic WordPress update checks
- **24 hours**: Maximum time for update visibility

## Security Features

### Data Protection

- **Encrypted Storage**: All GitHub credentials encrypted using WordPress security keys
- **Secure Transmission**: HTTPS for all GitHub API communications
- **No Logging**: Sensitive data never written to logs
- **Access Control**: Only administrators can configure updates

### Configuration Sources (Priority Order)

1. **WordPress Options** (encrypted in database)
2. **`.env` file** (local file, not committed)
3. **Environment Variables** (server-level)

## Troubleshooting

### Common Issues

**"Configuration test failed: Repository not found"**
- Check GitHub username and repository name spelling
- Ensure repository is public or token has access
- Verify repository exists and is accessible

**"API rate limit exceeded"**
- Add a GitHub Personal Access Token
- Token only needs public repository access (no special scopes)

**"No updates found"**
- Ensure version numbers match in plugin header and define statement
- Check that GitHub release tags start with 'v' (e.g., v3.0.1)
- Verify releases are not marked as draft

**"Update failed to install"**
- Check file permissions on wp-content/plugins/
- Ensure sufficient disk space
- Verify WordPress has write permissions

### Debug Information

Enable debug logging in WordPress:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `/wp-content/debug.log` for updater messages.

### Manual Update Check

Force an update check:
```php
// Add to functions.php temporarily
delete_site_transient('update_plugins');
wp_update_plugins();
```

## Advanced Configuration

### Custom Webhook Notifications

Set up immediate notifications to client sites:

1. **Add Repository Variable**: `WEBHOOK_URL` in GitHub repository settings
2. **Endpoint** receives POST requests with release information
3. **Client sites** can trigger immediate update checks

### Batch Client Management

For managing multiple client sites:

1. **Document** all client site URLs and update configurations
2. **Test updates** on staging sites first
3. **Monitor** update success across sites
4. **Rollback plan** if issues occur

### Version Numbering Strategy

Follow semantic versioning:
- **Major** (3.0.0): Breaking changes
- **Minor** (3.1.0): New features, backwards compatible
- **Patch** (3.0.1): Bug fixes, backwards compatible

## Backup and Recovery

### Before Major Updates

1. **Client site backups** before updating
2. **Database backups** of plugin settings
3. **Staging site testing** of new versions

### Rollback Process

If updates cause issues:
1. **Deactivate plugin** on affected sites
2. **Restore** from backup
3. **Fix issues** and create new release
4. **Test thoroughly** before re-deployment

## Support and Maintenance

### Regular Tasks

- **Monitor** GitHub Actions for failed releases
- **Test updates** on development sites
- **Review** client site update success
- **Update** GitHub tokens before expiration (if used)

### Client Communication

Inform clients about:
- **Update availability** (optional)
- **Major changes** that affect functionality
- **Required actions** after updates
- **Support contact** for update issues

---

## Quick Reference

### Release Workflow
1. **Develop features** → Code changes in any files
2. **Update version** → Change numbers in `ga4-server-side-tagging.php`
3. **Push to master** → `git push origin main`
4. **GitHub Actions** → Auto-creates release
5. **Client sites** → Show "Update Now" button within 12 hours

### Plugin Locations
- **Main Plugin File**: `ga4-server-side-tagging.php`
- **Updater Classes**: `includes/updater/`
- **Admin Interface**: WordPress Admin > GA4 Server-Side Tagging > Auto-Updates
- **Configuration**: `.env` file or WordPress admin

### Key URLs
- **GitHub Tokens**: https://github.com/settings/tokens
- **Repository Settings**: https://github.com/YOUR_USERNAME/ga4-server-side-tagging/settings
- **Actions**: https://github.com/YOUR_USERNAME/ga4-server-side-tagging/actions
- **Releases**: https://github.com/YOUR_USERNAME/ga4-server-side-tagging/releases

### Version Locations to Update (BOTH Required)
```php
// ga4-server-side-tagging.php
* Version: X.X.X                                     // Line 14 (header)
define('GA4_SERVER_SIDE_TAGGING_VERSION', 'X.X.X');  // Line 31 (constant)
```

### Example Version Update
```bash
# 1. Make your changes
git add .
git commit -m "Add new tracking feature"

# 2. Update version in ga4-server-side-tagging.php (both locations)
# 3. Commit version change
git add ga4-server-side-tagging.php
git commit -m "Release v3.0.1 - Added new tracking feature"

# 4. Push (triggers release)
git push origin main
```

This auto-update system provides a professional, secure, and scalable way to manage plugin updates across all your client sites.