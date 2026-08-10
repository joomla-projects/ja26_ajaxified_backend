/**
 * @copyright  (C) Open Source Matters, Inc.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

const CLASSIC_SCRIPT_TYPES = new Set([
    '',
    'application/ecmascript',
    'application/javascript',
    'application/x-ecmascript',
    'application/x-javascript',
    'text/ecmascript',
    'text/javascript',
    'text/x-ecmascript',
    'text/x-javascript',
]);

const attributesFrom = (element) => Array
    .from(element.attributes)
    .reduce((attributes, attribute) => {
        attributes[attribute.name] = attribute.value === ''
            ? true
            : attribute.value;

        return attributes;
    }, {});

const dependenciesFrom = (element) => {
    const dependencies = element.getAttribute('data-asset-dependencies');

    if (!dependencies) {
        return [];
    }

    return dependencies
        .split(',')
        .map((dependency) => dependency.trim())
        .filter(Boolean);
};

const normalizedTypeFrom = (element) => (
    element.getAttribute('type') || ''
).trim().toLowerCase();

const assetFromElement = (element, resourceAttribute, kind) => {
    const resource = element.getAttribute(resourceAttribute);

    return {
        kind,
        name: element.getAttribute('data-asset-name') || null,
        [resourceAttribute]: resource,
        inline: !resource,
        contentLength: resource ? 0 : element.textContent.length,
        dependencies: dependenciesFrom(element),
        attributes: attributesFrom(element),
    };
};

const importMapObjectFrom = (value) => (
    value && typeof value === 'object' && !Array.isArray(value)
        ? value
        : {}
);

const importMapEntriesFrom = (importMap) => {
    const imports = importMapObjectFrom(importMap.imports);
    const scopes = importMapObjectFrom(importMap.scopes);

    return [
        ...Object.entries(imports).map(([specifier, src]) => ({
            kind: 'importmap',
            name: specifier,
            specifier,
            src,
            scope: null,
        })),
        ...Object.entries(scopes).flatMap(([scope, scopeImports]) => (
            Object.entries(importMapObjectFrom(scopeImports))
                .map(([specifier, src]) => ({
                    kind: 'importmap',
                    name: specifier,
                    specifier,
                    src,
                    scope,
                }))
        )),
    ];
};

const freezeList = (list) => Object.freeze(
    list.map((item) => Object.freeze(item)),
);

const freezeAssets = (assets) => Object.freeze({
    modules: freezeList(assets.modules),
    classicScripts: freezeList(assets.classicScripts),
    styles: freezeList(assets.styles),
    importMaps: freezeList(assets.importMaps),
});

export default class AssetSynchronizer {
    /**
     * Discover server-declared runtime assets from a response snapshot.
     *
     * This phase is intentionally observational: it does not load,
     * register, or mutate any asset in the live document.
     *
     * @param {ResponseSnapshot|HTMLDocument} snapshot
     *
     * @returns {Object}
     */
    static collect(snapshot) {
        const document = snapshot.document || snapshot;
        const importMaps = this.importMapsFrom(document);

        return freezeAssets({
            modules: [
                ...this.moduleScriptsFrom(document),
                ...importMaps.flatMap((importMap) => importMap.entries),
            ],
            classicScripts: this.classicScriptsFrom(document),
            styles: this.stylesFrom(document),
            importMaps,
        });
    }

    /**
     * Discover module script declarations.
     *
     * @param {HTMLDocument} document
     *
     * @returns {Object[]}
     */
    static moduleScriptsFrom(document) {
        return Array
            .from(document.querySelectorAll('script'))
            .filter((script) => normalizedTypeFrom(script) === 'module')
            .map((script) => assetFromElement(script, 'src', 'script'));
    }

    /**
     * Discover classic script declarations.
     *
     * @param {HTMLDocument} document
     *
     * @returns {Object[]}
     */
    static classicScriptsFrom(document) {
        return Array
            .from(document.querySelectorAll('script'))
            .filter((script) => (
                !script.classList.contains('joomla-script-options')
                && CLASSIC_SCRIPT_TYPES.has(normalizedTypeFrom(script))
            ))
            .map((script) => assetFromElement(script, 'src', 'script'));
    }

    /**
     * Discover stylesheet and inline CSS declarations.
     *
     * @param {HTMLDocument} document
     *
     * @returns {Object[]}
     */
    static stylesFrom(document) {
        const stylesheetLinks = Array
            .from(document.querySelectorAll('link[href]'))
            .filter((link) => {
                const relations = (link.getAttribute('rel') || '')
                    .toLowerCase()
                    .split(/\s+/);

                return relations.includes('stylesheet')
                    || relations.includes('lazy-stylesheet');
            })
            .map((link) => assetFromElement(link, 'href', 'link'));

        const inlineStyles = Array
            .from(document.querySelectorAll('style'))
            .map((style) => assetFromElement(style, 'href', 'style'));

        return [
            ...stylesheetLinks,
            ...inlineStyles,
        ];
    }

    /**
     * Discover import map declarations.
     *
     * @param {HTMLDocument} document
     *
     * @returns {Object[]}
     */
    static importMapsFrom(document) {
        return Array
            .from(document.querySelectorAll('script'))
            .filter((script) => normalizedTypeFrom(script) === 'importmap')
            .map((script) => {
                let parsed = {};
                let parseError = null;

                try {
                    parsed = JSON.parse(script.textContent);
                } catch (error) {
                    parseError = error.message;
                }

                return {
                    kind: 'importmap',
                    imports: importMapObjectFrom(parsed.imports),
                    scopes: importMapObjectFrom(parsed.scopes),
                    entries: importMapEntriesFrom(parsed),
                    attributes: attributesFrom(script),
                    parseError,
                };
            });
    }

    /**
     * Compare the snapshot assets with the current window document runtime
     * and identify which assets are not currently satisfied.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {Object} Delta containing missing assets
     */
    static compare(snapshot) {
        const liveAssets = this.collect(window.document);

        // Normalization helper for URL comparison
        const normalize = (url) => {
            if (!url) return '';
            try {
                return new URL(url, window.document.baseURI).href;
            } catch {
                return url;
            }
        };

        const isAssetPresent = (asset, liveList, urlKey) => {
            return liveList.some((liveAsset) => {
                // If names match, it's present
                if (asset.name && liveAsset.name && asset.name === liveAsset.name) {
                    return true;
                }
                // If URLs match (normalized), it's present
                if (asset[urlKey] && liveAsset[urlKey]) {
                    return normalize(asset[urlKey]) === normalize(liveAsset[urlKey]);
                }
                // If both are inline, check content length similarity
                if (asset.inline && liveAsset.inline) {
                    return asset.contentLength === liveAsset.contentLength;
                }
                return false;
            });
        };

        // Reconcile modules (both module scripts and importmap entries)
        const missingModules = snapshot.assets.modules.filter((module) => {
            if (module.kind === 'importmap') {
                return !liveAssets.modules.some((liveModule) => {
                    return liveModule.kind === 'importmap'
                        && liveModule.name === module.name
                        && normalize(liveModule.src) === normalize(module.src)
                        && liveModule.scope === module.scope;
                });
            }
            return !isAssetPresent(module, liveAssets.modules.filter((m) => m.kind === 'script'), 'src');
        });

        // Reconcile classic scripts
        const missingClassicScripts = snapshot.assets.classicScripts.filter((script) => {
            return !isAssetPresent(script, liveAssets.classicScripts, 'src');
        });

        // Reconcile styles
        const missingStyles = snapshot.assets.styles.filter((style) => {
            return !isAssetPresent(style, liveAssets.styles, 'href');
        });

        // Reconcile import maps
        const missingImportMaps = snapshot.assets.importMaps.filter((map) => {
            return !liveAssets.importMaps.some((liveMap) => {
                return JSON.stringify(liveMap.imports) === JSON.stringify(map.imports)
                    && JSON.stringify(liveMap.scopes) === JSON.stringify(map.scopes);
            });
        });

        return {
            missingModules,
            missingClassicScripts,
            missingStyles,
            missingImportMaps,
        };
    }

    /**
     * Reconcile and load missing module assets.
     *
     * @param {ResponseSnapshot} snapshot
     *
     * @returns {Promise<void>}
     */
    static async synchronize(snapshot) {
        const delta = this.compare(snapshot);
        const loadPromises = delta.missingModules.map((module) => this.loadModule(module));
        await Promise.all(loadPromises);
    }

    /**
     * Load a single module script dynamically.
     *
     * @param {Object} module
     *
     * @returns {Promise<void>}
     */
    static loadModule(module) {
        if (module.inline || !module.src || module.kind !== 'script') {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            const script = document.createElement('script');
            script.type = 'module';
            script.src = module.src;

            // Copy attributes
            if (module.attributes && typeof module.attributes === 'object') {
                Object.entries(module.attributes).forEach(([name, value]) => {
                    // Do not override type and src
                    if (name !== 'type' && name !== 'src') {
                        script.setAttribute(name, value === true ? '' : value);
                    }
                });
            }

            // Ensure data-asset-name is set if name exists
            if (module.name && !script.hasAttribute('data-asset-name')) {
                script.setAttribute('data-asset-name', module.name);
            }

            script.addEventListener('load', () => resolve());
            script.addEventListener('error', () => {
                console.error(`[AssetSynchronizer] Failed to load module script: ${module.src}`);
                resolve(); // Resolve anyway so we do not block synchronization indefinitely
            });

            document.head.appendChild(script);
        });
    }
}
