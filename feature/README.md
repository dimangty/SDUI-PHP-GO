# Feature module policy

`feature/` uses incremental, feature-oriented extraction:

- every screen, ViewModel and feature state belongs to its own `*-ui` module;
- a separate `domain` or `data` module is created when the feature owns
  reusable business rules, repositories, caching, or a non-trivial mapping
  boundary;
- small features may call the typed `core:network` contract through their
  application coordinator until that boundary is justified;
- `feature:main` owns only navigation, session-wide state and composition. New
  screen-specific rules must not be added there.

`auth` is the reference full three-layer feature. `home` and `profile` already
own extracted domain/data modules. `cart`, `catalog`, `product`, `orders`, and
`devices` currently have UI-only modules because their persistence and wire
operations are thin; they should gain domain/data modules when they acquire
independent caching, reusable rules, or multiple data sources.

This is a deliberate threshold, not a requirement that every feature always
has three empty Gradle modules.
