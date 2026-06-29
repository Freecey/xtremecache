<?php
/**
 * Capture the rendered front-office page and expose it to esipagecache
 * through the custom "actionRequestComplete" hook.
 *
 * IMPORTANT: we do NOT reimplement the core rendering (which differs between
 * PS 1.6 and 1.7). We let the parent method render normally, capture its
 * output with an output buffer, fire the hook with the EXACT bytes the core
 * produced, then echo them unchanged. This keeps the page rendering 100%
 * identical to stock PrestaShop on any version.
 */
abstract class Controller extends ControllerCore
{
    protected function smartyOutputContent($content)
    {
        ob_start();
        parent::smartyOutputContent($content);
        $html = ob_get_clean();

        if ($this->controller_type == 'front' && $html !== '' && $html !== false) {
            Hook::exec('actionRequestComplete', array(
                'controller' => $this,
                'output' => $html,
            ));
        }

        echo $html;
    }
}
