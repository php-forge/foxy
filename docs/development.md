# Development

This document describes development workflows and maintenance tasks for the project.

## Sync Metadata

To keep configuration files synchronized with the latest template updates, use the `sync-metadata` command. This command
downloads the latest configuration files from the template repository.

```bash
composer sync-metadata
```

### Updated Files

This command updates the following configuration files:

| File               | Purpose                                      |
| ------------------ | -------------------------------------------- |
| `.editorconfig`    | Editor settings and code style configuration |
| `.gitattributes`   | Git attributes and file handling rules       |
| `.gitignore`       | Git ignore patterns and exclusions           |
| `.styleci.yml`     | StyleCI code style analysis configuration    |
| `infection.json5`  | Infection mutation testing configuration     |
| `phpstan.neon`     | PHPStan static analysis configuration        |
| `phpunit.xml.dist` | PHPUnit test configuration                   |

### When to Run

Run this command in the following scenarios:

- **Periodic Updates** - Monthly or quarterly to benefit from template improvements.
- **After Template Updates** - When the template repository has new configuration improvements.
- **Before Major Releases** - Ensure your project uses the latest best practices.
- **When Issues Occur** - If configuration files become outdated or incompatible.

### Important Notes

- This command overwrites existing configuration files with the latest versions from the template.
- Ensure you have committed any custom configuration changes before running this command.
- Review the updated files after syncing to ensure they work with your specific project needs.
- Some projects may require customizations after syncing configuration files.
