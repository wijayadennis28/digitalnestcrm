{{#if description}}<p>{{{description}}}</p>{{/if}}
{{#if name}}<p>{{name}}</p>{{/if}}

{{#each gridList}}
    {{#if header}}<p>{{header}}</p>{{/if}}

    {{#tableTag width="100%" border=1 cellpadding=5}}
        {{#each rowList}}
            {{#trTag}}
                {{#each this}}
                    {{#tdTag align=attrs.align}}
                        {{#if isBold}}<strong>{{/if}}
                        {{value}}
                        {{#if isBold}}</strong>{{/if}}
                    {{/tdTag}}
                {{/each}}
            {{/trTag}}
        {{/each}}
    {{/tableTag}}
    <p>&nbsp;</p>
{{/each}}

