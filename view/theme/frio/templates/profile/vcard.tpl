<div class="vcard h-card widget">

	<div id="profile-photo-wrapper">
		<a href="{{$profile.url}}"><img class="photo u-photo" src="{{$profile.photo}}" alt="{{$profile.name}}" /></a>
		<div class="tool visible-lg visible-md">
			{{if $profile.edit}}
			<div class="action">
				<a class="" href="{{$profile.edit.0}}" title="{{$profile.edit.3}}"><i class="fa fa-pencil-square-o"></i></a>
			</div>
			{{/if}}
		</div>

	</div>

	{{* The short information which will appended to the second navbar by scrollspy *}}
	<div id="vcard-short-info-wrapper" style="display: none;">
		<div id="vcard-short-info" class="media" style="display: none">
			<div id="vcard-short-photo-wrapper" class="pull-left">
				<img class="media-object" src="{{$profile.photo}}" alt="{{$profile.name}}"></a>
			</div>

			<div id="vcard-short-desc" class="media-body">
				<h4 class="media-heading">{{$profile.name}}</h4>
				{{if $profile.addr}}<div class="vcard-short-addr">{{$profile.addr}}</div>{{/if}}
			</div>
		</div>
	</div>

	<div class="panel-body">
		<div class="profile-header">
			<h3 class="fn p-name" dir="auto">{{$profile.name}}</h3>

			{{if $profile.addr}}<div class="p-addr">{{include file="sub/punct_wrap.tpl" text=$profile.addr}}</div>{{/if}}

			{{if $profile.about}}<div class="title" dir="auto">{{$profile.about nofilter}}</div>{{/if}}

			{{if $account_type}}<div class="account-type">({{$account_type}})</div>{{/if}}
		</div>

		{{if $follow_link || $unfollow_link || $wallmessage_link}}
		<div id="profile-extra-links">
			{{if $follow_link || $unfollow_link}}
			<div id="dfrn-request-link-button">
				{{if $unfollow_link}}
				<a id="dfrn-request-link" class="btn btn-labeled btn-primary btn-sm" href="{{$unfollow_link}}">
					<span class=""><i class="fa fa-user-times"></i></span>
					<span class="">{{$unfollow}}</span>
				</a>
				{{else}}
				<a id="dfrn-request-link" class="btn btn-labeled btn-primary btn-sm" href="{{$follow_link}}">
					<span class=""><i class="fa fa-user-plus"></i></span>
					<span class="">{{$follow}}</span>
				</a>
				{{/if}}
			</div>
			{{/if}}
			{{if $wallmessage_link}}
			<div id="wallmessage-link-botton">
				<button type="button" id="wallmessage-link" class="btn btn-labeled btn-primary btn-sm" onclick="openWallMessage('{{$wallmessage_link}}')">
					<span class=""><i class="fa fa-envelope"></i></span>
					<span class="">{{$wallmessage}}</span>
				</button>
			</div>
			{{/if}}
		</div>
		{{/if}}

		<div class="clear"></div>

		{{if $location}}
		<div class="location detail">
			<span class="location-label icon"><i class="fa fa-map-marker"></i></span>
			<span class="adr">
				{{if $profile.address}}<p class="street-address p-street-address">{{$profile.address nofilter}}</p>{{/if}}
				{{if $profile.location}}<p class="p-location">{{$profile.location}}</p>{{/if}}
			</span>
		</div>
		{{/if}}

		{{if $profile.xmpp}}
		<div class="xmpp">
			<span class="xmpp-label icon"><i class="fa fa-xmpp"></i></span>
			<span class="xmpp-data"><a href="xmpp:{{$profile.xmpp}}" rel="me" target="_blank" rel="noopener noreferrer">{{include file="sub/punct_wrap.tpl" text=$profile.xmpp}}</a></span>
		</div>
		{{/if}}

		{{if $profile.matrix}}
		<div class="matrix">
			<span class="matrix-label icon"><i class="fa fa-matrix-org"></i></span>
			<span class="matrix-data"><a href="matrix:{{$profile.matrix}}" rel="me" target="_blank" rel="noopener noreferrer">{{include file="sub/punct_wrap.tpl" text=$profile.matrix}}</a></span>
		</div>
		{{/if}}

		{{if $profile.upubkey}}<div class="key u-key" style="display:none;">{{$profile.upubkey}}</div>{{/if}}

		{{if $contacts}}<div class="contacts" style="display:none;">{{$contacts}}</div>{{/if}}

		{{if $updated}}<div class="updated" style="display:none;">{{$updated}}</div>{{/if}}

		{{if $homepage}}
		<div class="homepage detail">
			<span class="homepage-label icon"><i class="fa fa-external-link"></i></span>
			<span class="homepage-url u-url"><a href="{{$profile.homepage}}" rel="me" target="_blank" rel="noopener noreferrer">{{include file="sub/punct_wrap.tpl" text=$profile.homepage}}</a></span>
		</div>
		{{/if}}

		{{if $about}}<dl class="about"  style="display:none;"><dt class="about-label">{{$about}}</dt><dd class="x-network">{{$profile.about nofilter}}</dd></dl>{{/if}}

		{{include file="diaspora_vcard.tpl"}}
	</div>
</div>

{{if $contact_block}}
<div class="widget" id="widget-contacts">
	{{$contact_block nofilter}}
</div>
{{/if}}
