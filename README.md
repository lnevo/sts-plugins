# STS plugins

Addons for the Shipper-Driven Traffic Simulator (sts-docker). Each addon lives in
`plugins/<id>/plugin.php` and returns a manifest consumed by `sts/plugin_registry.php`
in sts-docker. docker-compose in sts-docker bind-mounts `plugins/` onto
`/var/www/html/sts/plugins` in the web container.

Contract: see sts-docker `docs/plans/plugin-hook-contract.md`.

## Layout values

Only `hart_layout` names HART's jobs, places and shipments. The other plugins
take layout values from settings, each on its own Settings page
(`plugin.php?id=<plugin>&page=settings`):

- `jmri_ops`: the JMRI address and the STS-to-JMRI name map (`jmri_ops.json`).
- `track_scale`: `track_scale_config.json` in its data folder.
- `coke_orders`: the outbound and replenish shipments and the keep prefix
  (`coke_orders.json`).

A layout plugin can preset them in its manifest, `'presets' => ['track_scale' =>
[...], 'coke_orders' => [...]]`, and register its own weigh routine for a job
with `'track_scale_job_weigh' => ['JOB' => callable]`. A saved settings file
wins over the preset, and the preset over the built-in defaults, which name
nothing. `hart_layout/hart_presets.php` holds HART's.

`tests/check_generic_plugins.sh` fails when a HART name turns up outside
`plugins/hart_layout`. Each plugin's tests are in `plugins/<id>/tests/` and run
with `php`.

