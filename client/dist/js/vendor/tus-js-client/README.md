# tus-js-client 4.3.1 (vendored)

Unmodified copy of `dist/tus.min.js`, `dist/tus.min.js.map` and `LICENSE` (MIT) from the npm
package `tus-js-client@4.3.1`, loaded by `BunnyUploadField` instead of a CDN copy (issue #5).

Verify or update:

    npm pack tus-js-client@<version>      # integrity must equal `npm view tus-js-client@<version> dist.integrity`
    tar xzf tus-js-client-<version>.tgz
    shasum -a 256 package/dist/tus.min.js # 4.3.1: 8cbb1b63fccc3bba0ae73ad1deb160ce046c3750851d0c3e94921ae3ef070eb8

Copy the three files here, then update the version in this file, in `BunnyUploadField::Field()`
and in the CHANGELOG.
