# STS plugins

Addons for the Shipper-Driven Traffic Simulator (sts-docker). Each addon lives in
`plugins/<id>/plugin.php` and returns a manifest consumed by `sts/plugin_registry.php`
in sts-docker. docker-compose in sts-docker bind-mounts `plugins/` onto
`/var/www/html/sts/plugins` in the web container.

Contract: see sts-docker `docs/plans/plugin-hook-contract.md`.
