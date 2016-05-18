define([
  'react'
],
function(
  React
) {
  const FormFieldText = React.createClass({
    render: function() {
      let value = this.props.item[this.props.field.name];
      if(value === undefined) {
        value = this.props.field.defaultValue || '';
      }

      return (
        <input
          type="text"
          disabled={
            (this.props.field['disabled'] !== undefined)
            ? this.props.field.disabled(this.props.item)
            : false
          }
          className={ (this.props.field.size) ? '' : 'regular-text' }
          size={
            (this.props.field.size !== 'auto' && this.props.field.size > 0)
            ? this.props.field.size
            : false
          }
          name={ this.props.field.name }
          id={ 'field_'+this.props.field.name }
          value={ value }
          placeholder={ this.props.field.placeholder }
          onChange={ this.props.onValueChange }
          {...this.props.field.validation}
        />
      );
    }
  });

  return FormFieldText;
});
