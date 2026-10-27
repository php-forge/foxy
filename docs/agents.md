# AI assistance

The canonical source is [`skills/php-forge-foxy/SKILL.md`](../skills/php-forge-foxy/SKILL.md). It helps agents configure
Foxy, select a frontend manager and verify merges and audits against the installed revision.

## Install in an application

### Automatic Composer installation

Complete the [one-time Composer setup](https://yii2-framework.github.io/docs/project/ai-agents/#composer-installation)
for `llm/skills`, enable `auto-sync` in `skills.json`, and explicitly trust `php-forge/foxy` in
`dependencies.composer.trusted`. The installed package must include its skill and declare `extra.skills.source`.

```bash
composer install
composer skills:show
```

With `auto-sync` disabled, run `composer skills:update` after installing or updating dependencies. Installing Foxy alone
does not make its skill discoverable from the application root.

### Optional npx installation

List and install the skill from a checkout or a release that contains it, using the application root:

```bash
npx skills@1.7.2 add /absolute/path/to/foxy --list
npx skills@1.7.2 add /absolute/path/to/foxy \
  --skill php-forge-foxy --agent claude-code codex opencode
```

A vendor path can be used when its installed version includes the skill. List a remote release before installing it;
local changes are not necessarily published. Installer links and copies are outputs, not separately maintained sources.

## Discovery and verification

The checkout's `.agents/skills/` and `.claude/skills/` links point to the canonical bundle. For manual installation copy
or link the whole `php-forge-foxy` directory under the client's project skill directory. Codex uses `.agents/skills/`
and can invoke `$php-forge-foxy`; Claude Code uses `.claude/skills/` and `/php-forge-foxy`; OpenCode can use
`.agents/skills/` through its skill tool.

Verify installation, discovery in a fresh client session, explicit activation, and successful task behavior separately.
`--list` and manually reading the skill do not prove client activation. Follow the installed revision's guides and check
the generated manifest and selected manager in a disposable project.

## Next steps

- 📚 [Getting started](index.md)
- ⚙️ [Configuration reference](config.md)
- 💡 [Usage guide and examples](usage.md)
- 🧪 [Testing guide](testing.md)

[Back to documentation](../README.md#documentation)
