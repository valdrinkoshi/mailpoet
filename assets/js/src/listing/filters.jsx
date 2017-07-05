define([
  'react',
  'jquery',
  'mailpoet'
],
function (
  React,
  jQuery,
  MailPoet
) {
  var ListingFilters = React.createClass({
    handleFilterAction: function () {
      let filters = {};
      this.getAvailableFilters().map((filter, i) => {
        filters[this.refs['filter-'+i].name] = this.refs['filter-'+i].value;
      });
      return this.props.onSelectFilter(filters);
    },
    handleEmptyTrash: function () {
      return this.props.onEmptyTrash();
    },
    getAvailableFilters: function () {
      let filters = this.props.filters;
      return Object.keys(filters).filter(function (filter) {
        return !(
          filters[filter].length === 0
          || (
            filters[filter].length === 1
            && !filters[filter][0].value
          )
        );
      });
    },
    render: function () {
      const filters = this.props.filters;
      const available_filters = this.getAvailableFilters()
        .map(function (filter, i) {
          return (
            <select
              ref={ `filter-${i}` }
              key={ `filter-${i}` }
              name={ filter }
            >
            { filters[filter].map(function (option, j) {
              return (
                <option
                  value={ option.value }
                  key={ 'filter-option-' + j }
                >{ option.label }</option>
              );
            }.bind(this)) }
            </select>
          );
      }.bind(this));

      let button;

      if (available_filters.length > 0) {
        button = (
          <input
            id="post-query-submit"
            onClick={ this.handleFilterAction }
            type="submit"
            defaultValue={MailPoet.I18n.t('filter')}
            className="button" />
        );
      }

      let empty_trash;
      if (this.props.group === 'trash') {
        empty_trash = (
          <input
            onClick={ this.handleEmptyTrash }
            type="submit"
            value={MailPoet.I18n.t('emptyTrash')}
            className="button"
          />
        );
      }

      return (
        <div className="alignleft actions actions">
          { available_filters }
          { button }
          { empty_trash }
        </div>
      );
    }
  });

  return ListingFilters;
});
