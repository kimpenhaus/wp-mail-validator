jQuery(document).ready(() => {
  if (jQuery("#default_gateway").length) {
    jQuery("#default_gateway").mask("099.099.099.099");
  }
  if (jQuery("#trashmail_service_blacklist_restore").length) {
      jQuery("#trashmail_service_blacklist_restore").click((event) => {
        jQuery('input[name=wp_mail_validator_options_update_type]').val('restore_trashmail_blacklist');
      });
  }
  if(jQuery("#trashmail_service_blacklist").length) {
    jQuery("#trashmail_service_blacklist_line_count").html(getLineCount(jQuery("#trashmail_service_blacklist")));
    jQuery("#trashmail_service_blacklist").on('input', function() {
        jQuery("#trashmail_service_blacklist_line_count").html(getLineCount(jQuery("#trashmail_service_blacklist")));
    });
  }
  if(jQuery("#user_defined_blacklist").length) {
    jQuery("#user_defined_blacklist_line_count").html(getLineCount(jQuery("#user_defined_blacklist")));
    jQuery("#user_defined_blacklist").on('input', function() {
        jQuery("#user_defined_blacklist_line_count").html(getLineCount(jQuery("#user_defined_blacklist")));
    });
  }
});

function getLineCount(element) {
    return element.val().split(/\n/).filter(function(el) { return el.length != 0}).length;
}