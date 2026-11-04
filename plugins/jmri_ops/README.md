# JMRI Operations sync (`jmri_ops`)

Sends every car's STS location, load and final destination to JMRI Operations
Pro, so JMRI's roster, manifests and CATS show the same railroad as STS. STS is
the master; JMRI is a copy that this step refreshes.

You get:

- a workflow step, **Send Cars to JMRI**, in the session editor's step adder
  (group *During*). Put it after the steps that move cars, or at the end of a
  session. Its *Preview only* option asks JMRI what would change and changes
  nothing.
- a **Send Cars to JMRI** button on the Operations page. It lists what STS would
  send, and has **Preview** and **Send to JMRI** buttons.

## Set up

1. In JMRI, add `sts_car_sync.py` to Start Up and restart JMRI. HART's deploy
   does this on the Pi and the Mac (`jmri/layouts/hart/scripts/` in the hart
   repo). JMRI's web server must be running, which it is when Start Up includes
   it.
2. On the STS host, create `jmri_ops.json` in the STS config folder
   (`sts-docker-data/config/`, which is `/var/lib/sts` in the container):

   ```json
   { "url": "http://minipc-e5h6x.local:12080", "map": "hart", "timeout": 30 }
   ```

   `STS_JMRI_URL` in the container's environment overrides `url`. With no url
   the step does nothing and says so, which is what the sandbox stack gets.

## What is sent

| STS | JMRI |
|---|---|
| Car's current location | Location and track from `maps/hart.json`: by location code, then by station (the offline parties). A blank track keeps the car's JMRI track at that location, or takes the first track that accepts the car |
| `Loaded` or `Unloading` | The shipment's commodity, through the `loads` table in the map |
| `Empty`, `Ordered`, `Loading` | JMRI's empty load (`E`) |
| `Loaded` | Final destination: the shipment's unloading location |
| `Ordered` | Final destination: the shipment's loading location |
| `Empty` with an E-waybill | Final destination: the location in the order |
| Anything else, or already there | Final destination cleared |
| `Unavailable` | Not sent. Out of service reaches JMRI through `bin/cars publish` (hart ADR-015) |

JMRI leaves a car alone while it is on a built train. A car whose STS location
has no JMRI location in the map is not sent, and the page says why. When STS has
a car on a track JMRI would refuse (wrong car type, say), the car is still
placed where STS has it, with a note, because STS is the master.

JMRI saves Operations after a send that changed something (the step's *Save
Operations in JMRI* option). On the Pi, run `make ops-pull` in the hart repo
before the next deploy to keep that state.

## How it talks to JMRI

JMRI's JSON server can move a car but cannot set its load or final destination.
So the batch is written to the Memory `IMSTS_CAR_SYNC_REQUEST`, and
`sts_car_sync.py`, running inside JMRI, applies it and answers in
`IMSTS_CAR_SYNC_REPLY`. `IMSTS_CAR_SYNC_READY` holds its version once it is
listening. Nothing touches turnouts, signals or MQTT.

## Files

- `plugin.php`: manifest
- `jmri_ops_helpers.php`: config, map, batch building, HTTP
- `jmri_ops_plugin.php`: the workflow step
- `jmri_ops.php`: the page (plain forms, no scripts)
- `maps/hart.json`: HART's STS-to-JMRI names
- `tests/test_jmri_ops.php`: `php plugins/jmri_ops/tests/test_jmri_ops.php`
  (uses `tests/fake_jmri.php` with `php -S`)
