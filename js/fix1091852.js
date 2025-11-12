(function ($, Drupal, window) {
  Drupal.behaviors.samlauthfix = {
    attach(context, settings) {
      // Copy states.js but reprocess only the AJAX additions. It can make all
      // of them invisible.
      const $states = $(context).find("[id^=edit-idp-certs-]");
      const il = $states.length;

      const _loop = (i) => {
        const config = JSON.parse($states[i].getAttribute("data-drupal-states"));
        Object.keys(config || {}).forEach((state) => {
          const d = new Drupal.states.Dependent({
            element: $($states[i]),
            state: Drupal.states.State.sanitize(state),
            constraints: config[state],
          });
          // This is basically the 1091852-131 patch. I hope.
          d.reevaluate();
        });
      };

      for (let i = 0; i < il; i++) {
        _loop(i);
      }
    },
  };
})(jQuery, Drupal, window);
