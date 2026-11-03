# @prc/nexus-self-improving-guidelines

## 1.2.1

### Patch Changes

- 867486e: The post publish pipeline now comes from `plugins/prc-shared/primitives/` instead of the `prc-post-publish-pipeline` plugin. These plugins no longer list that plugin in `Requires Plugins`. Publish, update, unpublish, and trash hooks fire the same way as before. The mu-plugin loader boots the pipeline once, so PRC Shared now runs it on the platform.
- 1d96db5: These plugins now require the renamed PRC Shared plugin, `prc-shared`, instead of `prc-scripts`.
