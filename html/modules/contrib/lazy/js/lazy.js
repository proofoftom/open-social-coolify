/**
 * @file
 * The Lazy-load behavior.
 */

((Drupal) => {
  Drupal.behaviors.lazy = {
    attach(context, settings) {
      const utils = {
        extend(obj, src) {
          Object.keys(src).forEach((key) => {
            obj[key] = src[key];
          });
          return obj;
        },
        once(selector) {
          return (context || document).querySelector(selector);
        },
        loadScript(url) {
          if (document.querySelectorAll(`script[src="${url}"]`).length === 0) {
            const script = document.createElement('script');
            const scripts = document.getElementsByTagName('script')[0];
            script.src = url;
            script.async = true;
            scripts.parentNode.insertBefore(script, scripts);
          }
        },
      };

      if (utils.once('body', context)) {
        const lazysizes = settings.lazy.lazysizes || {};

        if (!settings.lazy.preferNative) {
          // 1. Lazysizes configuration.
          window.lazySizesConfig = window.lazySizesConfig || {};
          window.lazySizesConfig = utils.extend(
            window.lazySizesConfig,
            lazysizes,
          );
          // 2. Load all selected lazysizes plugins.
          if (!Object.entries) {
            Object.entries = (obj) => {
              const ownProps = Object.keys(obj);
              let i = ownProps.length;
              const resArray = new Array(i);
              while (i--) {
                resArray[i] = [ownProps[i], obj[ownProps[i]]];
              }
              return resArray;
            };
          }
          const min = settings.lazy.minified ? '.min' : '';
          Object.entries(lazysizes.plugins).forEach((path) => {
            utils.loadScript(
              `${settings.lazy.libraryPath}/plugins/${path[1]}${min}.js`,
            );
          });
          // 3. Load the lazysizes library.
          utils.loadScript(`${settings.lazy.libraryPath}/lazysizes${min}.js`);
        }
      }
    },
  };
})(Drupal);
