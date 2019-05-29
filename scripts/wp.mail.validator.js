jQuery(document).ready(() => {
  if (jQuery("#default_gateway").length) {
    jQuery("#default_gateway").mask("099.099.099.099");
  }
  if (jQuery("#trashmail_service_blacklist_restore").length) {
      jQuery("#trashmail_service_blacklist_restore").click((event) => {
        jQuery('input[name=wp_mail_validator_options_update_type]').val('restore_trashmail_blacklist');
      });
  }
});
