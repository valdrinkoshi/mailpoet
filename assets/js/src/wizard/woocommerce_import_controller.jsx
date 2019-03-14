import PropTypes from 'prop-types';
import React from 'react';
import MailPoet from 'mailpoet';
import WelcomeWizardHeader from './header.jsx';
import WooCommerceImportListStep from './steps/woo_commerce_import_list_step.jsx';

class WooCommerceImportController extends React.Component {
  constructor(props) {
    super(props);

    this.state = {
      loading: false,
    };
    this.updateSettings = this.updateSettings.bind(this);
  }


  finishWizard() {
    this.setState({ loading: true });
    window.location = window.finish_wizard_url;
  }

  updateSettings(data) {
    this.setState({ loading: true });
    return MailPoet.Ajax.post({
      api_version: window.mailpoet_api_version,
      endpoint: 'settings',
      action: 'set',
      data,
    }).then(() => this.setState({ loading: false })).fail((response) => {
      this.setState({ loading: false });
      if (response.errors.length > 0) {
        MailPoet.Notice.error(
          response.errors.map(error => error.message),
          { scroll: true }
        );
      }
    });
  }

  render() {
    return (
      <div className="mailpoet_welcome_wizard_steps mailpoet_welcome_wizard_centered_column">
        <WelcomeWizardHeader
          current_step={0}
          steps_count={0}
          logo_src={window.mailpoet_logo_url}
        />
        <WooCommerceImportListStep loading={this.state.loading} />
      </div>
    );
  }
}

WooCommerceImportController.propTypes = {};

export default WooCommerceImportController;