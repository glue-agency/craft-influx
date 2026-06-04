# Test project config

`craftcms/test-framework` seeds this directory into Craft at suite boot.
The feature tests assume the following minimum surface:

- **Site** `default` (primary), plus `nl` for multi-site tests
- **Section** `articles` (Channel)
- **Entry type** `article` attached to `articles`, with the field set below
- **Volume** `testUploads` (`local` filesystem) so the Assets-upload tests can
  create real files in `tests/_output/uploads/`
- **Tag group** `topics`
- **Category group** `regions`
- **Fields**:
  | handle      | type                       | notes                                      |
  |-------------|----------------------------|--------------------------------------------|
  | `summary`   | `craft\fields\PlainText`   |                                            |
  | `featured`  | `craft\fields\Lightswitch` |                                            |
  | `region`    | `craft\fields\Dropdown`    | options: `north`, `south`                  |
  | `cover`     | `craft\fields\Assets`      | sources: `volume:testUploads`              |
  | `related`   | `craft\fields\Entries`     | sources: `section:articles`                |
  | `regions`   | `craft\fields\Categories`  | source: `group:regions`                    |
  | `topics`    | `craft\fields\Tags`        | source: `taggroup:topics`                  |
  | `importId`  | `craft\fields\PlainText`   | used as the match key                      |

If you haven't seeded this yet:

```sh
# from your real Craft install, export a snapshot of the above
./craft project-config/touch
cp -R config/project ../craft-influx/tests/_craft/config/project
```

…then trim it down to just the entries above so feature tests don't have to
load the whole site.
