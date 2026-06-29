<?php
/**
 * Capture the rendered front-office page and expose it to esipagecache
 * through the custom "actionRequestComplete" hook.
 *
 * IMPORTANT — why FrontController and not Controller:
 * in PrestaShop 1.7 (and 1.6) FrontControllerCore defines its OWN
 * smartyOutputContent(), which shadows any override placed on Controller.
 * The store hook therefore MUST be installed on FrontController, whose method
 * is the one actually invoked by FrontControllerCore::display() for category /
 * product / listing pages. (Validated on a real PrestaShop 1.7.6.1 install:
 * overriding Controller never fired; overriding FrontController works.)
 *
 * We do NOT reimplement the core rendering (which differs between versions).
 * We let the parent render normally, capture its output with an output buffer,
 * fire the hook with the EXACT bytes the core produced, then echo them
 * unchanged — so the page stays byte-identical to stock PrestaShop.
 */
class FrontController extends FrontControllerCore
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
