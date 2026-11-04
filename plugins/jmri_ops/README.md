# JMRI Operations sync (`jmri_ops`)

Sends every car's STS location, load and final destination to JMRI Operations
Pro, so JMRI's roster, manifests and any dispatcher reading them show the same
railroad as STS. STS is the master; JMRI is a copy that this plugin refreshes.

Nothing in the plugin knows a particular layout. How STS reaches JMRI, and which
JMRI location, track and load each STS name stands for, are settings.

You get:

- **Settings** (`plugin.php?id=jmri_ops&page=settings`): the JMRI address, and
  the map from STS locations, stations and commodities to JMRI names. **Test
  connection** loads JMRI's own names as suggestions, and **Check names against
  JMRI** marks any name in the map that JMRI does not have. The page also shows
  the settings file, which you can copy to keep, or paste in to restore.
- A workflow step, **Send Cars to JMRI**, in the session editor's step adder
  (group *During*). Put it after the steps that move cars, or at the end of a
  session. *Preview only* asks JMRI what would change and changes nothing.
- A **Send Cars to JMRI** button on the Operations page, listing what STS would
  send, with **Preview** and **Send to JMRI**.

## Set up

1. Put `sts_car_sync.py` in JMRI's Start Up and restart JMRI. JMRI's web server
   must be running (also a Start Up item).
2. Open **Settings**, enter the JMRI web server (`http://<host>:12080`), and
   press **Test connection and load JMRI names**.
3. Fill in the map:
   - **STS locations → JMRI**: a JMRI location for each STS location, and a
     track where STS names one spot. Leave the track blank for a yard; JMRI then
     keeps the car's track there, or takes the first track that accepts it.
   - **STS stations → JMRI**: for many STS locations that share one JMRI track,
     such as offline parties in staging. A location without its own row uses its
     station's row; a location with neither is not sent.
   - **STS commodities → JMRI loads**.
   - **Car statuses never sent**: `Unavailable` by default.
4. **Check names against JMRI**, then **Save settings**.

Settings are saved to `jmri_ops.json` in the STS config folder
(`/var/lib/sts` in the container, `sts-docker-data/config/` on the host), with
the previous file kept as `jmri_ops.json.bak`. `STS_JMRI_URL` in the
container's environment overrides the saved address. With no address the step
does nothing and says so.

## What is sent

| STS | JMRI |
|---|---|
| Car's current location | Location and track from the map |
| `Loaded` or `Unloading` | The shipment's commodity, through the loads map |
| `Empty`, `Ordered`, `Loading` | JMRI's empty load |
| `Loaded` | Final destination: the shipment's unloading location |
| `Ordered` | Final destination: the shipment's loading location |
| `Empty` with an E-waybill | Final destination: the location in the order |
| Anything else, or already there | Final destination cleared |
| A status in *never sent* | Not sent |

JMRI leaves a car alone while it is on a built train. When STS has a car on a
track JMRI would refuse (wrong car type, say), the car is still placed where STS
has it, with a note in the preview, because STS is the master. If *JMRI saves
Operations after a send* is on, JMRI saves after any send that changed a car.

## How it talks to JMRI

JMRI's JSON server can move a car but cannot set its load or final destination.
So STS writes each request to the JMRI Memory `IMSTS_CAR_SYNC_REQUEST`;
`sts_car_sync.py`, running inside JMRI, does it and answers in
`IMSTS_CAR_SYNC_REPLY`. `IMSTS_CAR_SYNC_READY` holds the script's version once
it is listening; version 2 or later is needed for the settings page's JMRI
names. Nothing touches turnouts, signals or MQTT.

## Files

- `plugin.php`: manifest
- `jmri_ops_helpers.php`: settings, batch building, HTTP
- `jmri_ops_plugin.php`: the workflow step
- `jmri_ops.php`: the Send page; `jmri_ops_settings.php`: Settings (plain forms,
  no scripts)
- `tests/test_jmri_ops.php`: `php plugins/jmri_ops/tests/test_jmri_ops.php`
  (a made-up layout; uses `tests/fake_jmri.php` with `php -S`)
