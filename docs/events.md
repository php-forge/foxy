# Events

Foxy dispatches Composer events that allow another Composer plugin to inspect or extend asset solving. An extension must
implement Composer's plugin and event subscriber contracts; application event handlers are not registered automatically.

See Composer's [plugin documentation](https://getcomposer.org/doc/articles/plugins.md) for plugin setup and security
requirements.

## Event names and classes

The `Foxy\FoxyEvents` constants contain event names. Event classes are in the `Foxy\Event` namespace. Each event
provides the installed Composer packages and the mock asset directory through `getPackages()` and `getAssetDir()`.

| Constant                 | Event name        | Event class      | Timing                                                                    |
| ------------------------ | ----------------- | ---------------- | ------------------------------------------------------------------------- |
| `FoxyEvents::PRE_SOLVE`  | `foxy.pre-solve`  | `PreSolveEvent`  | Before mock packages are rebuilt.                                         |
| `FoxyEvents::GET_ASSETS` | `foxy.get-assets` | `GetAssetsEvent` | After Foxy builds the asset map and before it is merged into the project. |
| `FoxyEvents::POST_SOLVE` | `foxy.post-solve` | `PostSolveEvent` | After the manager returns and before Composer fallback handling.          |

These events run while Foxy handles Composer's `post-install-cmd` or `post-update-cmd` event.

## pre-solve

`PreSolveEvent` exposes:

- `getAssetDir()` for the directory in which mock frontend packages will be created.
- `getPackages()` for the installed Composer packages considered by the solve operation.

The event is informational; the asset map has not been built yet.

## get-assets

`GetAssetsEvent` exposes the common getters and these methods:

- `getAssets()` returns the map of frontend package names to package definition paths.
- `hasAsset(string $name)` checks whether an entry exists.
- `addAsset(string $name, string $path)` adds or replaces an entry.

The path passed to `addAsset()` may identify a package definition file, which Foxy normalizes relative to the consuming
`package.json`, or it may be a ready `file:` directory reference. Use a project-relative manifest path when possible.

## post-solve

`PostSolveEvent` exposes the common getters and `getRunResult()`, which returns the frontend manager process exit code.
The event is dispatched before Foxy attempts to restore captured Composer lock and vendor state after a non-zero
result. It is not dispatched when manager execution throws before returning a result.

## Subscriber example

```php
<?php

declare(strict_types=1);

namespace Acme\FoxyExtension;

use Composer\EventDispatcher\EventSubscriberInterface;
use Foxy\Event\GetAssetsEvent;
use Foxy\Event\PostSolveEvent;
use Foxy\FoxyEvents;

final class FoxySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            FoxyEvents::GET_ASSETS => 'onGetAssets',
            FoxyEvents::POST_SOLVE => 'onPostSolve',
        ];
    }

    public function onGetAssets(GetAssetsEvent $event): void
    {
        if (!$event->hasAsset('@composer-asset/acme--theme')) {
            $event->addAsset(
                '@composer-asset/acme--theme',
                'vendor/acme/theme/package.json',
            );
        }
    }

    public function onPostSolve(PostSolveEvent $event): void
    {
        $result = $event->getRunResult();

        // Report or observe the result without changing Foxy's fallback behavior.
    }
}
```

## Next steps

- 📚 [Getting started](index.md)
- ⚙️ [Configuration reference](config.md)
- 💡 [Usage guide](usage.md)
- ❓ [Frequently asked questions](faqs.md)
- 🧪 [Testing guide](testing.md)
- ⬆️ [Upgrade guide](../UPGRADE.md)
- 📖 [README](../README.md)
