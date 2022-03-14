/**
 * Add JavaScript confirmation to Delete buttons
 */
jQuery(function () {
    jQuery('.twofactor_delconfirm').click(function (event) {
        if (window.confirm(LANG.del_confirm)) return;
        event.preventDefault();
        event.stopPropagation();
    });
    jQuery('.twofactor_resetconfirm').click(function (event) {
        if (window.confirm(LANG.plugins.twofactor.reset_confirm)) return;
        event.preventDefault();
        event.stopPropagation();
    });
});
