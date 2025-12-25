# xAPI Learning Records Viewer (Tsugi Edition)

A Tsugi-based LTI tool that allows students to view their xAPI learning records and automatically syncs grades back to the LMS. Supports both **LTI 1.1** and **LTI 1.3 (Advantage)**.

## Features

- **LTI 1.1 & 1.3 Support**: Works with any LTI-compliant LMS (Canvas, Moodle, Brightspace, etc.)
- **xAPI Statement Retrieval**: Fetches learning records from any xAPI-compliant LRS
- **Activity Grouping**: Organizes activities into parent-child hierarchies with task tracking
- **Automatic Grade Passback**: Syncs grades back to the LMS gradebook
- **Multi-LMS Compatible**: Tested with Canvas, Moodle, and Brightspace
- **Standalone or Tsugi Integration**: Can run independently or integrate with a full Tsugi installation

## Quick Start

### Using Docker Compose

```bash
cd tsugi-xapi-viewer
docker-compose up -d
```

Access the tool at `http://localhost:8080`

### Manual Installation

1. Copy files to your web server (PHP 8.1+ with Apache)
2. Copy `config.php` and update settings
3. Ensure the `keys/` directory is writable (for LTI 1.3)
4. Configure your LMS using the registration page

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `LTI_CONSUMER_KEY` | LTI 1.1 OAuth consumer key | `xapi_viewer_key` |
| `LTI_CONSUMER_SECRET` | LTI 1.1 OAuth consumer secret | `xapi_viewer_secret` |
| `LRS_ENDPOINT` | xAPI LRS endpoint URL | `http://sql-lrs:8080/xapi` |
| `LRS_API_KEY` | LRS API key | `my_api_key` |
| `LRS_API_SECRET` | LRS API secret | `my_api_secret` |
| `LTI13_CLIENT_ID` | LTI 1.3 Client ID | - |
| `LTI13_PRIVATE_KEY` | Path to RSA private key for LTI 1.3 | - |
| `APP_TIMEZONE` | Application timezone | `America/New_York` |

### LTI 1.3 Setup

For LTI 1.3 support, you need to:

1. Generate an RSA key pair:
   ```bash
   openssl genrsa -out keys/private.pem 2048
   openssl rsa -in keys/private.pem -pubout -out keys/public.pem
   ```

2. Set the `LTI13_PRIVATE_KEY` environment variable to the path of your private key

3. Register your tool with the LMS using the configuration from `/register.php?format=json`

## LMS Integration

### Registration Page

Visit `/register.php` to get LTI configuration details for your LMS:

- `/register.php` - Interactive registration page with instructions
- `/register.php?format=xml` - LTI 1.1 XML configuration
- `/register.php?format=json` - LTI 1.3 JSON configuration
- `/register.php?format=canvas` - Canvas-specific XML configuration

### Canvas LMS

1. Go to **Settings > Apps > +App**
2. Select **Configuration Type: By URL**
3. Enter the Configuration URL: `https://your-domain/tsugi-xapi-viewer/register.php?format=canvas`
4. Enter the Consumer Key and Secret
5. Click **Submit**

### Moodle

1. Go to **Site administration > Plugins > External tool > Manage tools**
2. Click **Configure a tool manually**
3. Enter the Tool URL
4. Enter the Consumer Key and Secret
5. Under **Privacy**, enable sharing of email and name
6. Under **Services**, enable grade synchronization

### Brightspace (D2L)

1. Go to **Admin Tools > External Learning Tools**
2. Click **New Link**
3. Enter the URL and credentials
4. Enable **Send user email** and **Send user name**
5. Enable **Support Outcomes** for grade passback

## Custom Parameters

Configure these LTI custom parameters to control activity matching:

| Parameter | Description |
|-----------|-------------|
| `custom_lab_id` | Match a specific xAPI activity by ID substring (e.g., `cli-desktop`) |

## Activity Matching

The tool automatically matches LTI launches to xAPI activities using:

1. **custom_lab_id**: Explicit activity ID matching (highest priority)
2. **resource_link_title**: Matches against xAPI activity names
3. **Fuzzy matching**: Finds similar activity names when exact match fails

## Grade Calculation

Grades are calculated as follows:

1. If the activity has an explicit score, use that score
2. If the activity has child tasks, calculate `passed_tasks / total_tasks`
3. Based on status: `passed/mastered = 100%`, `completed = 100%`, `failed = 0%`

## Directory Structure

```
tsugi-xapi-viewer/
├── index.php           # Main application entry point
├── app.php             # Tsugi initialization
├── config.php          # Configuration settings
├── register.php        # LTI registration page
├── css/
│   └── styles.css      # Application styles
├── lib/
│   ├── xapi_functions.php      # xAPI helper functions
│   └── tsugi_standalone.php    # Standalone Tsugi bootstrap
├── lti13/
│   ├── login.php       # LTI 1.3 OIDC login initiation
│   └── jwks.php        # LTI 1.3 JSON Web Key Set
├── keys/               # RSA keys for LTI 1.3 (create this directory)
├── Dockerfile          # Docker image configuration
├── docker-compose.yml  # Docker Compose configuration
└── .htaccess           # Apache configuration
```

## Using with Full Tsugi Installation

If you have a full Tsugi installation:

1. Place this folder in the Tsugi tools directory
2. Update `app.php` to use the correct path to `tsugi.php`
3. The tool will use Tsugi's database, session management, and grade passback

## Development

### Running Locally

```bash
# Start with Docker Compose
docker-compose up -d

# View logs
docker-compose logs -f xapi-viewer

# Rebuild after changes
docker-compose build --no-cache
docker-compose up -d
```

### Testing LTI Launches

You can test LTI launches using tools like:
- [LTI Test Tool](https://lti.tools/saltire/)
- [IMS LTI Reference Implementation](https://www.imsglobal.org/activity/learning-tools-interoperability)

## Troubleshooting

### Common Issues

**"Please launch this tool from your LMS"**
- The tool was accessed directly instead of via LTI launch
- Ensure your LMS is properly configured

**"Invalid OAuth signature"**
- Check that the consumer key and secret match in both LMS and tool
- Verify the tool URL matches exactly

**"Email not available"**
- Configure your LMS to share user email addresses
- In Canvas: Set Privacy to "Public"
- In Moodle: Enable "Share launcher's email with tool"

**"No matching activity found"**
- Set a `custom_lab_id` parameter in your LTI link
- Ensure activity names in xAPI match the LMS assignment names

### Debug Mode

Enable debug mode by setting `APP_DEBUG=true` in your environment.

## License

This project is released under the MIT License.

## Credits

Built with [Tsugi](https://www.tsugi.org/) - a framework for building interoperable learning tools.
