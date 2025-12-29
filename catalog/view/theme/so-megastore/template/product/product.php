{{ header }}

{#====  Loader breadcrumbs ==== #}
{% include theme_directory~'/template/soconfig/breadcrumbs.twig' %}

{#====  Variables url parameter ==== #}
{% if url_asidePosition %}{% set col_position = url_asidePosition %}
{% else %}{% set col_position = soconfig.get_settings('catalog_col_position') %}{% endif %}

{% if url_asideType %} {% set col_canvas = url_asideType %}
{% else %}{% set col_canvas = soconfig.get_settings('catalog_col_type') %}{% endif %} 

{% if url_productGallery %} {% set productGallery = url_productGallery %}
{% else %}{% set productGallery = soconfig.get_settings('thumbnails_position') %}{% endif %}

{% if url_sidebarsticky %} {% set sidebar_sticky = url_sidebarsticky %}
{% else %} {% set sidebar_sticky = soconfig.get_settings('catalog_sidebar_sticky') %}{% endif %}

{% set desktop_canvas = col_canvas =='off_canvas' ? 'desktop-offcanvas' : '' %} 
 
<div class="content-main container product-detail  {{desktop_canvas}}">
	<div class="row">
		
		{#==== Column Left Outside ==== #}
 
		{% if col_position== 'outside' %}
			{{ column_left }}
			
			{% if col_canvas =='off_canvas' %}
				{% set class_pos = 'col-sm-12' %}
	    	{% elseif column_left and column_right %}
	    		{% set class_pos = 'col-md-6 col-xs-12 fluid-allsidebar' %}
		    {% elseif column_left or column_right %}
		    	{% set class_pos = 'col-md-9 col-sm-12 col-xs-12 fluid-sidebar' %}
		    {% else %}
		    	{% set class_pos = 'col-sm-12' %}
		    {% endif %}
		{% else %}
			{% set class_pos = 'col-sm-12' %}
		{% endif %}
		{#==== End Column Outside ==== #}
    	
		<div id="content" class="product-view {{class_pos}}"> 
		
		{#====  Product Gallery ==== #}
		{% if productGallery =='grid' %}
			{% set class_left_gallery  = 'col-md-6 col-sm-12 col-xs-12' %}
			{% set class_right_gallery = 'col-md-6 col-sm-12 col-xs-12' %}
		{% elseif productGallery =='list' %}
			{% set class_left_gallery  = 'col-md-5 col-sm-12 col-xs-12' %}
			{% set class_right_gallery = 'col-md-7 col-sm-12 col-xs-12' %}
		{% elseif productGallery =='left' %}
			{% set class_left_gallery  = 'col-md-6 col-sm-12 col-xs-12' %}
			{% set class_right_gallery = 'col-md-6 col-sm-12 col-xs-12' %}
			{% elseif productGallery =='bottom' %}
		{% set class_left_gallery  = 'col-md-5 col-sm-12 col-xs-12' %}
			{% set class_right_gallery = 'col-md-7 col-sm-12 col-xs-12' %}
		{% else %}
			{% set class_left_gallery  = 'col-md-12 col-sm-12 col-xs-12' %}
			{% set class_right_gallery = 'col-md-12 col-sm-12 col-xs-12 col-gallery-slider' %}
		{% endif %}

		{#====  Button Sidebar canvas==== #}
		{% if column_left or column_right %}
			{% set class_canvas = col_canvas =='off_canvas' ? '' : 'hidden-lg hidden-md' %}
			<a href="javascript:void(0)" class=" open-sidebar {{class_canvas}}"><i class="fa fa-bars"></i>{{ text_sidebar }}</a>
			<div class="sidebar-overlay "></div>
		{% endif %}

		<div class="content-product-mainheader clearfix"> 
			<div class="row">	
			{#========== Product Left ============#}
			<div class="content-product-left  {{ class_left_gallery }}" >
				{% if images %}
					<div class="so-loadeding" ></div>
					{#==== Gallery -  Thumbnails ==== #}
					{% if productGallery=='left' %}
					 	{% include theme_directory~'/template/product/gallery/gallery-left.twig' %}

					{% elseif productGallery=='bottom' %}
						{% include theme_directory~'/template/product/gallery/gallery-bottom.twig' %}

					{% elseif productGallery=='grid' %}
						{% include theme_directory~'/template/product/gallery/gallery-grid.twig' %}

					{% elseif productGallery=='list' %}
						{% include theme_directory~'/template/product/gallery/gallery-list.twig' %}

					{% elseif productGallery=='slider' %}
						{% include theme_directory~'/template/product/gallery/gallery-slider.twig' %}
					{% endif %}
				{% endif %}
			</div>
        	{#========== //Product Left ============#}

			{#========== Product Right ============#}
			<div class="content-product-right {{ class_right_gallery }}" >

				<div class="title-product">
						 <h1 class="title-category">{{heading_title}}</h1>
					</div>
				
						{% if review_status %}
					{#======== Review - Rating ========== #}
					<div class="box-review"  >
					
						
						<div class="rating">
							<div class="rating-box">
							{% for i in 1..5 %}
								{% if rating < i %}<span class="fa fa-stack"><i class="fa fa-star-o fa-stack-1x"></i></span>

{% else %}

<span class="fa fa-stack"><i class="fa fa-star fa-stack-1x"></i><i class="fa fa-star-o fa-stack-1x"></i></span>{% endif %}
							{% endfor %}
							</div>
						</div>
						<a class="reviews_button" href="" onclick="$('a[href=\'#tab-review\']').trigger('click'); return false;">{{ reviews }}</a>
						{% if soconfig.get_settings('product_order') %}
									
			
						{% endif %}
					
					</div>
					{% endif %}

				{% if price %}
					{#========= Product - Price ========= #}

					<div class="product_page_price price">
						{% if not special %}
							<span class="price-new">
								<span content="{{ price_value }}" id="price-old">{{ price }}</span>
								<meta content="{{currency}}" />
							</span>

						{% else %}
						
							<span class="price-new">
								<span content="{{special_value}}" id="price-special">{{ special }}</span>
								<meta content="{{currency}}" />
							</span>
						   <span class="price-old" id="price-old"> 
								
		
						   </span>
						   
						{% endif %}
						
						{% if special and  soconfig.get_settings('discount_status')   %} 
						{#=======Discount Label======= #}
						<span class="price-old" id="price-old"> 
								{{ price }} </span> 
                        <span class="label-product label-sale">
							 {{ discount }} 
						</span>
                         
						{% endif %} 

						{% if tax %}
							<div class="price-tax"><span>{{ text_tax }}</span> <span id="price-tax"> {{ tax }} </span></div>
						{% endif %}
						<a href="{{ manufacturers }}" title="Click To See All Products Made By {{ manufacturer }}"><span class="manufacturer-image-right"> <img class="brand-logo-product" src="{{ manufacturer_logo }}" alt="Click To See All Products Made By {{ manufacturer }} Sold At Bragainbasement.club" title="Click To See All Products Made By {{ manufacturer }} Sold At Bragainbasement.club"></span></a>
						
					
					</div>
					{% endif %}
					

				{% if discounts %} 
					<ul class="list-unstyled text-success">
					{% for discount in discounts %} 
						<li><strong>{{ discount.quantity }} {{ text_discount }} {{ discount.price }}</strong> </li>
					{% endfor %}
					</ul>
				{% endif %} 	

				<div class="product-box-desc">
				<h3 class="title-category">Product Details</h3>
					<div class="inner-box-desc">

						{% if manufacturer %}
							        <div>
							        <span class="model">Manufacturer: </span><a href="{{ manufacturers }}" title="Click To See All Products Made By {{ manufacturer }} Sold At Bragainbasement.club"><span class="normal"><h2 class="tag-h2">{{ manufacturer }}</h2></span></a>
									
			
                                    </div>
							{% endif %}
						
						{% if model %}
                      <div class="model"><span>{{ text_model }} </span><span class="normal"><font data-ro="product-model">{{ model }}</font></span></div>
						{% endif %}						
						
						

						
						<div class="stock"><span>{{ text_stock }} </span><span class="normal"><font data-ro="product-stock"><div id="bg-poa-status"></div></font></span></div>	
						
					
						<div class="stock"><span>Warranty:</span><span class="normal"><a href="/returns-policy" target="_blank" title="Click To View Our Returns Policy" alt="Click To View Our Returns Polic">12 Month LImited Warranty</a></span></div>
						
		
						
					</div>	
						
					{% if soconfig.get_settings('product_enablesold')   %}
					<div class="inner-box-sold ">

						<div class="viewed"><span>{{ text_viewed }}</span> <span class="label label-primary">{{ viewed }}</span></div>	
						{% if sold %}
						<div class="sold"><span>{{ text_sold_ready }}</span> <span class="label label-success"> {{ sold }} </span></div>	
						{% endif %}
					</div>	
					{% endif %}
					
					{% if soconfig.get_settings('product_enablesizechart') %}
						<a class="image-popup-sizechart" href="image/{{soconfig.get_settings('img_sizechart')}}" >{{ text_size_chart }} </a>	
				    {% endif %}

				</div>
				


				{#===== Show CountDown Product =======#}
				{% if soconfig.get_settings('countdown_status') and special_end_date %}
              <h3  class="title-category">Sale Ends In</h3>
					{% include theme_directory~'/template/soconfig/countdown.twig' with {product: product,special_end_date:special_end_date} %}
				{% endif %}
				
				
				<div id="product">	
					{% if options %} 
					<h3 class="title-category">Available Options</h3>
					{% for option in options %}
						
						{% if option.type == 'select' %}
						<div class="select-option">
							<select name="option[{{ option.product_option_id }}]" id="input-option{{ option.product_option_id }}" class="form-control width50">
								<option value="">{{ option.name }}</option>
							{% for option_value in option.product_option_value %}
								{# Do NOT disable OOS options; allow click/preview. Stock is shown dynamically and Add To Cart is disabled instead. #}
								<option value="{{ option_value.product_option_value_id }}"
								        data-ov="{{ option_value.option_value_id }}"
								        {% if loop.first %}selected="selected"{% endif %}
								        {% if option_value.quantity is defined and option_value.quantity <= 0 and option_value.subtract != 0 %}title="Out of stock"{% endif %}>{{ option_value.name }}
								{% if option_value.price %}
									({{ option_value.price_prefix }}{{ option_value.price }})
								{% endif %}
								</option>
							{% endfor %}
						  </select>
						</div>
						{% endif %}
						
						
						
						
						
						
						{% if option.type == 'radio' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						<div id="input-option{{ option.product_option_id }}">
							<label class="option-label  {{ option.name }}"><span class="option-label-text"> {{ option.name }}</span></label>
							
							
							

								{% set radio_style 	 = soconfig.get_settings('radio_style') %}
								{% set radio_type 	 = radio_style ? ' radio-type-button':'' %}

								{% for option_value in option.product_option_value %} 
								{% set radio_image 	=  option_value.image ? 'option_image' : '' %} 
								{% set radio_price 	=  radio_style ? option_value.price_prefix ~ option_value.price : '' %} 
								
									<div class="radio {{ radio_image ~ radio_type }}">
										<label>							
										{% set available = (option_value.subtract == 0 or option_value.quantity > 0) %}
<input type="radio"
name="option[{{ option.product_option_id }}]"
value="{{ option_value.product_option_value_id }}"
data-ov="{{ option_value.option_value_id }}"
{% if loop.first %}checked="checked"{% endif %}
{# Do NOT disable OOS radios; allow selection. #}
{% if not available %}title="Out of stock"{% endif %} />

											<span class="option-content-box {{ option.name }} blinking{% if loop.first %} active{% endif %}" data-title="{{ option_value.name}} {{ radio_price }}" title ="Select {{ option.name }} {{ option_value.name}}">
												
										{% if option.name|lower == 'color' and option_value.image %}
  <img
    src="{{ option_value.image|e }}"
    alt="{{ option_value.name|e }}" title ="Select {{ option.name }} {{ option_value.name}}"
    
  />
{% else %}
  <span class="sizes-top sizes width">{{ option_value.name|e }}</span>
{% endif %}
													<span class="option-name">
													
													<span class="size-number-caps" >
													{{ option_value.name }} </span>
                                                    
													</span>
													{% if option_value.price  and  radio_style  != '1' %} ({{ option_value.price_prefix }} {{ option_value.price }} ){% endif %} 
											 											</span>
											
										</label>
									</div>
									{% endfor %}	
								 
								{% if radio_style %} 
								<script type="text/javascript">
									 $(document).ready(function(){
										  $('#input-option{{ option.product_option_id }} ').on('click', 'span', function () {
											   $('#input-option{{ option.product_option_id }}  span').removeClass("active");
											   $(this).toggleClass("active");
										  });
									 });
								</script>
								{% endif %} 

							</div>
						</div>
						{% endif %}

						{% if option.type == 'checkbox' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  	<label>{{ option.name }}</label>
						  	<div id="input-option{{ option.product_option_id }}">
								{% set radio_style 	 = soconfig.get_settings('radio_style') %}
								{% set radio_type 	 = radio_style ? ' radio-type-button':'' %}

								{% for option_value in option.product_option_value %} 
								{% set radio_image 	=  option_value.image ? 'option_image' : '' %} 
								{% set radio_price 	=  radio_style ? option_value.price_prefix ~ option_value.price : '' %} 
								
									<div class="checkbox  {{ radio_image ~ radio_type }}">
										<label>
											{# Do NOT disable OOS checkboxes; allow selection. #}
											<input type="checkbox"
											       name="option[{{ option.product_option_id }}][]"
											       value="{{ option_value.product_option_value_id }}"
											       data-ov="{{ option_value.option_value_id }}"
											       {% if loop.first %}checked="checked"{% endif %}
											       {% if option_value.quantity is defined and option_value.quantity <= 0 and option_value.subtract != 0 %}title="Out of stock"{% endif %} />
											<span class="option-content-box{% if loop.first %} active{% endif %}" data-title="{{ option_value.name}} {{ radio_price }}" data-toggle='tooltip'>
												{% if option_value.image %} 
													<img src="{{ option_value.image }} " alt="{{ option_value.name}}  {{radio_price}}" /> 
												{% endif %} 

												<span class="option-name">{{ option_value.name }} </span>
												{% if option_value.price  and  radio_style  != '1' %} 
													({{ option_value.price_prefix }} {{ option_value.price }} )
												{% endif %} 
											  
											</span>
										</label>
									</div>
								{% endfor %}	
								 
								{% if radio_style %} 
								<script type="text/javascript">
									 $(document).ready(function(){
										  $('#input-option{{ option.product_option_id }} ').on('click', 'span', function () {
											   $(this).toggleClass("active");
										  });
									 });
								</script>
								{% endif %} 

							</div>
						</div>
						{% endif %}

						{% if option.type == 'text' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  <label class="title-categorysub" for="input-option{{ option.product_option_id }}">Select:  {{ option.name }}</label>
						  <input type="text" name="option[{{ option.product_option_id }}]" value="{{ option.value }}" placeholder="{{ option.name }}" id="input-option{{ option.product_option_id }}" class="form-control" />
						</div>
						{% endif %}

						{% if option.type == 'textarea' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  <label class="title-categorysub" for="input-option{{ option.product_option_id }}">Select:  {{ option.name }}</label>
						  <textarea name="option[{{ option.product_option_id }}]" rows="5" placeholder="{{ option.name }}" id="input-option{{ option.product_option_id }}" class="form-control">{{ option.value }}</textarea>
						</div>
						{% endif %}

						{% if option.type == 'file' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  <label class="title-categorysub">Select: {{ option.name }}</label>
						  <button type="button" id="button-upload{{ option.product_option_id }}" data-loading-text="{{ text_loading }}" class="btn btn-default btn-block"><i class="fa fa-upload"></i> {{ button_upload }}</button>
						  <input type="hidden" name="option[{{ option.product_option_id }}]" value="" id="input-option{{ option.product_option_id }}" />
						</div>
						{% endif %}

						{% if option.type == 'date' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  <label class="title-categorysub" for="input-option{{ option.product_option_id }}">{{ option.name }}</label>
						  <div class="input-group date">
							<input type="text" name="option[{{ option.product_option_id }}]" value="{{ option.value }}" data-date-format="YYYY-MM-DD" id="input-option{{ option.product_option_id }}" class="form-control" />
							<span class="input-group-btn">
							<button class="btn btn-default" type="button"><i class="fa fa-calendar"></i></button>
							</span></div>
						</div>
						{% endif %}

						{% if option.type == 'datetime' %}
						<div class="form-group{% if option.required %} required {% endif %}">
						  <label class="title-categorysub" for="input-option{{ option.product_option_id }}">{{ option.name }}</label>
						  <div class="input-group datetime">
							<input type="text" name="option[{{ option.product_option_id }}]" value="{{ option.value }}" data-date-format="YYYY-MM-DD HH:mm" id="input-option{{ option.product_option_id }}" class="form-control" />
							<span class="input-group-btn">
							<button type="button" class="btn btn-default"><i class="fa fa-calendar"></i></button>
							</span></div>
						</div>
						{% endif %}
						
						{% if option.type == 'time' %}
						<div class="form-group{% if option.required %} required {% endif %}">
							<label class="title-categorysub" for="input-option{{ option.product_option_id }}">{{ option.name }}</label>
							<div class="input-group time">
							<input type="text" name="option[{{ option.product_option_id }}]" value="{{ option.value }}" data-date-format="HH:mm" id="input-option{{ option.product_option_id }}" class="form-control" />
							<span class="input-group-btn">
							<button type="button" class="btn btn-default"><i class="fa fa-calendar"></i></button>
							</span></div>
						</div>
						{% endif %}
						
					{% endfor %}
					{% endif %}

					<div class="box-cart clearfix form-group">
						{% if recurrings %}
						<h3>{{ text_payment_recurring }}</h3>
						<div class="form-group required">
							<select name="recurring_id" class="form-control">
							<option value="">{{ text_select }}</option>
							{% for recurring in recurrings %}
							<option value="{{ recurring.recurring_id }}">{{ recurring.name }}</option>
							{% endfor %}
							</select>
						  <div class="help-block" id="recurring-description"></div>
						</div>
						{% endif %}
					   <div class="title-category-product">
                                Add To Cart & Wishlist
                              </div>
						<div class="form-group box-info-product">
							<div class="option quantity">
								<div class="input-group quantity-control">
									  <span class="input-group-addon product_quantity_down fa fa-minus mminus"></span>
									  <input class="form-control" type="text" name="quantity" value="{{ minimum }}" />
									  <input type="hidden" name="product_id" value="{{ product_id }}" />								  
									  <span class="input-group-addon product_quantity_up fa fa-plus plus"></span>
								</div>
							</div>
							<div class="detail-action">
                             
								{# =========button Cart ======#}
								<div class="cart"><input type="button" value="Add To Basket" data-loading-text="{{ text_loading }}" id="button-cart" class="btn btn-mega btn-lg"></div>
								<div class="add-to-links wish_comp">
									<ul class="blank">
										<li class="wishlist">
											<a onclick="wishlist.add({{ product_id }});"><i class="fa fa-heart"></i></a>
										</li>
																						
									</ul>
								</div>
							</div>
						</div>
<div class="ShowHide">	
<span class="notice-popup">If the product has option <strong>IE:</strong> colour etc, Then select the option before adding item to cart</span>
<table border="0" width="100%">
	<tr>
	<td class="logo-popup"><img src="https://www.bargainbasement.club/image//catalog/blog/logo_image.png"  alt="Bargainbasement.club" title="Bargaibasement.club" class="logo-popup-top">
	</td>
		<td class="popup-image"><img itemprop="image" class="product-image-zoom lazyload top-popup" data-sizes="auto" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="{{popup}}" data-zoom-image="{{popup}}" title="{{ seo_h1 }} - {{ model }} - bargainbasement.club" alt="{{ seo_h1 }}  - {{ model }} - bargainbasement.club" />
		

			
	
		
		
		</div>
</td>
<td class="opup-details">
{{heading_title}}
{% if price %}
					{#========= Product - Price ========= #}

					<div class="product_page_price price" >
						{% if not special %}
							<span class="price-new">
								<span  id="price-old">{{ price }}</span>
								
							</span>

						{% else %}
						
							<span class="price-new">
								<span id="price-special">{{ special }}</span>
								
							</span>
						   <span class="price-old" id="price-old"> 
								
		
						   </span>
						   
						{% endif %}
						{% if soconfig.get_settings('countdown_status') and special_end_date %}
             					{% include theme_directory~'/template/soconfig/countdown1.twig' with {product: product,special_end_date:special_end_date} %}
				{% endif %}
						{% if special and  soconfig.get_settings('discount_status')   %} 
						{#=======Discount Label======= #}
						<span class="price-old" id="price-old"> 
								{{ price }} </span>
                        <span class="label-product label-sale">
							 {{ discount }} 
						</span>
                        
						{% endif %} 
						{% endif %} 
						{% if review_status %}
					{#======== Review - Rating ========== #}
					<div class="box-review"  >
						
						
						<div class="rating">
							<div class="rating-box">
							{% for i in 1..5 %}
								{% if rating < i %}<span class="fa fa-stack"><i class="fa fa-star-o fa-stack-1x"></i></span>

{% else %}

<span class="fa fa-stack"><i class="fa fa-star fa-stack-1x"></i><i class="fa fa-star-o fa-stack-1x"></i></span>{% endif %}
							{% endfor %}
							</div>
								{#===== Show CountDown Product =======#}
				
						</div>
						
					
					</div>
					{% endif %}
		<td class="product-popup-details"><div class="form-group box-info-product">
<div class="option quantity">
<div class="input-group quantity-control">
<span class="input-group-addon product_quantity_down fa fa-minus mminus"></span>
<input class="form-control" type="text" name="quantity" value="{{ minimum }}" />
<input type="hidden" name="product_id" value="{{ product_id }}" /> 
<span class="input-group-addon product_quantity_up fa fa-plus plus"></span>
</div>
</div>
<div class="detail-action">

{# =========button Cart ======#}
<div class="cart"><input type="button" value="{{ button_cart }}" data-loading-text="{{ text_loading }}" id="button-cart1" class="btn btn-mega btn-lg"></div>
<div class="add-to-links wish_comp">
<ul class="blank">
<li class="wishlist">
<a onclick="wishlist.add({{ product_id }});"><i class="fa fa-heart"></i></a>
</li>

</ul>
</div>

</div></div>
</td>
	</tr>
</table>
</div><div class="clearfix"></div>
						{% if minimum > 1 %}
							<div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ text_minimum }}</div>
						{% endif %}
					</div>
<div class="title-category-product">
                                Social & Sharing
                              </div>

<div class="sharethis-inline-share-buttons"></div>

					 
				</div>
					 {% if reviewfeatured %}
				  <h3 class="title-category"> {{ textadmin.text_featured }}</h3>
			<div class="featuredreview"></div>
			{% endif %}
			</div>
			{#========== //Product Right ============#}
			</div>
		</div>
					{#====  content_Top==== #}
		{% if content_top %}
		<div class="content-product-maintop form-group clearfix">
			{{ content_top }}
		</div>
		{% endif %}
		<div class="content-product-mainbody clearfix row">
			
			{% if col_position== 'inside' %}
			{#====  Column left inside==== #}
				{{ column_left }}
			    {% if col_canvas =='off_canvas' %}
					{% set class_left = 'col-sm-12' %}
		    	{% elseif column_left and column_right %}
		    		{% set class_left = 'col-md-6 col-column3' %}
			    {% elseif column_left or column_right %}
			    	{% set class_left = 'col-md-9 col-sm-12 col-xs-12' %}
			    {% else %}
			    	{% set class_left = 'col-sm-12' %}
			    {% endif %}
			{% else %}
				{% set class_left = 'col-sm-12' %}
			{% endif %}

		    <div class="content-product-content {{ class_left }}">
				<div class="content-product-midde clearfix">
					{#========== TAB BLOCK ============#}
					{% set related_position = soconfig.get_settings('tabs_position') == 1 ? 'vertical-tabs' : ''  %}
					{% set tabs_position	= soconfig.get_settings('tabs_position')  %}
					{% set showmore			= soconfig.get_settings('product_enableshowmore')  %}
					{% if showmore %} {% set class_showmore = 'showdown' %}
					{% else %} {% set class_showmore = 'showup' %}
					{% endif %}

					{# --- Determine has_specs: prefer controller-provided has_specs, else compute here as fallback --- #}
					{% if has_specs is not defined %}
						{% set has_specs = false %}
						{% if attribute_groups is defined and attribute_groups %}
							{% for ag in attribute_groups %}
								{% if ag.attribute is defined and ag.attribute %}
									{% for a in ag.attribute %}
										{% set a_name = a.name|default('')|trim %}
										{% set a_text = a.text|default('')|trim %}
										{% if a_name != '' or a_text != '' %}
											{% set has_specs = true %}
										{% endif %}
									{% endfor %}
								{% endif %}
							{% endfor %}
						{% endif %}
					{% endif %}

					<div class="producttab ">
						<div class="tabsslider {{related_position}} {% if tabs_position == 1 %} {{'vertical-tabs'}} {% else %} {{'horizontal-tabs'}} {% endif %} col-xs-12">
							{#========= Tabs - Bottom horizontal =========#}
							{% if tabs_position == 2 %}
							<ul class="nav nav-tabs font-sn">
								<li class="active"><a data-toggle="tab" href="#tab-description">{{ tab_description }}</a></li>
								
					         {% if has_specs %}
					            <li><a class="tab-title" href="#tab-specs" data-toggle="tab">{{ tab_attribute }}</a></li>
					         {% endif %}
					         
					            {% if review_status %}
					           	 <li><a href="#tab-review" data-toggle="tab">{{ tab_review }}</a></li>
					            {% endif %}
												
								{% if soconfig.get_settings('product_enableshipping') %}
								 <li><a href="#tab-contentshipping" data-toggle="tab">{{ tab_shipping}}</a></li>
								{% endif %}

								{% if product_tabtitle %}
					           	 <li><a href="#tab-customhtml" data-toggle="tab">{{ product_tabtitle}}</a></li>
					            {% endif %}

								{% if product_video %}
					           	 <li><a class="thumb-video" href="{{product_video}}"><i class="fa fa-youtube-play fa-lg"></i> {{ tab_video}}</a></li>
					            {% endif %}
																
							</ul>

							{#========= Tabs - Left vertical =========#}
							{% elseif tabs_position == 1  %}
								<ul class="nav nav-tabs col-lg-3 col-sm-4">
								<li class="active"><a class="tab-title" data-toggle="tab" href="#tab-description">{{ tab_description }}</a></li>
								
								
			{% if customtabs %}
			{% for key, customtab in customtabs %}
				<li><a href="#tabcustom{{ key }}" data-toggle="tab">{{ customtab.title }}</a></li>
            {% endfor %}
            {% endif %}
						
					            {% if has_specs %}
					            	<li><a class="tab-title" href="#tab-specs" data-toggle="tab">{{ tab_attribute }}</a></li>
					            {% endif %}
					            
					            {% if review_status %}
					           	 <li><a class="tab-title" href="#tab-review" data-toggle="tab">{{ tab_review }}</a></li>
					            {% endif %}
								
								{% if soconfig.get_settings('product_enableshipping')  %}
								 <li><a class="tab-title" href="#tab-contentshipping" data-toggle="tab">{{ tab_shipping}}</a></li>
								{% endif %}

								{% if product_tabtitle %}
					           	 <li><a class="tab-title" href="#tab-customhtml" data-toggle="tab">{{ product_tabtitle}}</a></li>
					            {% endif %}
					            
								{% if product_video %} 
					           	 <li><a class="tab-title" class="thumb-video" href="{{product_video}}"><i class="fa fa-youtube-play fa-lg"></i> {{ tab_video}}</a></li>
					            {% endif %}
                                 <li><a  class="tab-title" href="#tab-qap" data-toggle="tab">{{ tab_qap }}</a></li>
														 
								</ul>
							{% endif %}

							<div class="tab-content {% if tabs_position == 1  %} {{ 'col-lg-9 col-sm-8' }} {% endif %} col-xs-12">
								<div class="tab-pane active" id="tab-description">
															
								 
						            <div id="collapse-description" class="desc-collapse {{class_showmore}}">
									<h2 class="prod-desc-title">{{ seo_h1 }}</h2>
																	<div class="desc-image-box">
										<h5 class="pdesc-box">{{ description }}</h5>
										<div class="product-box-desc-details">
				
					
<span><b>Product Details</b><br></span> 
						{% if manufacturer %}
							       <span>Manufacturer: </span><a href="{{ manufacturers }}" title="Click To See All Products Made By {{ manufacturer }} Sold At Bragainbasement.club"><span class="normal"><h2 class="prod-desc">{{ manufacturer }}</h2></span></a>
                                    
							{% endif %}
						
						{% if model %}
						<div><span>{{ text_model }}</span><span class="normal"><h3 class="tag-h3"> <font data-ro="product-model">{{ model }}</font></h3></span></div>
						{% endif %}
						<div><span>Category: </span><span class="normal"> {{ seo_h2 }}</span></div>
						<div><span>{{ text_stock }} </span><span class="normal"> <font data-ro="product-stock">{{ stock }}</font></span></div>	
						</div>
										</div>
										<h4 class="desc-image">{{ seo_h1 }}</h4>
										<div class="bottom-prod-image-full-size">
										<div class="desc-image-box">
										

<div id="thumb-slider" class="gallery-slider contentslider contentslider--default" data-rtl="{{direction}}" data-autoplay="no"  data-pagination="yes" data-delay="4" data-speed="0.6" data-margin="0"  data-items_column0="1" data-items_column1="1" data-items_column2="1" data-items_column3="1" data-items_column4="1" data-arrows="yes" data-loop="yes" data-center="yes" data-loop="yes" data-hoverpause="yes">
	{% for key,image in images %}
		<a class="thumbnail-2" title="{{ seo_h1 }} - {{ model }} - bargainbasement.club">
			<img class="bottom-product-image" src="{{ image.popup }}" title="{{ seo_h1 }} - {{ model }} - bargainbasement.club" alt="{{ seo_h1 }} - {{ model }} - bargainbasement.club" />
		</a>
	{% endfor %}
	
</div>

							


										</div>
										</div></div> 

 
									{% if showmore %}
									<div class="button-toggle">
								         <a class="showmore" data-toggle="collapse" href="#" aria-expanded="false" aria-controls="collapse-footer">
								            <span class="toggle-more">{{ objlang.get('show_more') }} <i class="fa fa-angle-down"></i></span> 
			 					            <span class="toggle-less">{{ objlang.get('show_less') }} <i class="fa fa-angle-up"></i></span>           
										</a>        
									</div>
									{% endif %}
								</div> 

				{% if customtabs %}
            {% for key, customtab in customtabs %}
				<div class="tab-pane"id="tabcustom{{ key }}">
					{{ customtab.description }}
				</div>
			{% endfor %}
			{% endif %}
								
{% if qas %}
			<div class="tab-pane" id="tab-qap">{{ qas }}</div>
			{% endif %}
		
					            {% if review_status %}
					            <div class="tab-pane" id="tab-review">
						            <form class="form-horizontal" id="form-review">
						                <div id="review"></div>
						                <h3>{{ text_write }}</h3>
						                {% if review_guest %}
						                <div class="form-group required">
						                  <div class="col-sm-12">
						                    <label class="control-label" for="input-name">{{ entry_name }}</label>
						                    <input type="text" name="name" value="{{ customer_name }}" id="input-name" class="form-control" />
						                  </div>
						                </div>
						                <div class="form-group required">
						                  <div class="col-sm-12">
						                    <label class="control-label" for="input-review">{{ entry_review }}</label>
						                    <textarea name="text" rows="5" id="input-review" class="form-control"></textarea>
						                    <div class="help-block">{{ text_note }}</div>
						                  </div>
						                </div>
						                <div class="form-group required">
						                  <div class="col-sm-12">
						                    <label class="control-label">{{ entry_rating }}</label>
						                    &nbsp;&nbsp;&nbsp; {{ entry_bad }}&nbsp;
						                    <input type="radio" name="rating" value="1" />
						                    &nbsp;
						                    <input type="radio" name="rating" value="2" />
						                    &nbsp;
						                    <input type="radio" name="rating" value="3" />
						                    &nbsp;
						                    <input type="radio" name="rating" value="4" />
						                    &nbsp;
						                    <input type="radio" name="rating" value="5" />
						                    &nbsp;{{ entry_good }}</div>
						                </div>
						                {{ captcha }}
						                
						                  <div class="pull-right">
						                    <button type="button" id="button-review" data-loading-text="{{ text_loading }}" class="btn btn-primary">{{ button_continue }}</button>
						                  </div>
						                {% else %}
						                {{ text_login }}
						                {% endif %}
						            </form>
					            </div>
					            {% endif %}

					            {% if soconfig.get_settings('product_enableshipping') and soconfig.get_settings('product_contentshipping') %}
								<div class="tab-pane" id="tab-contentshipping">
									{{ soconfig.decode_entities( soconfig.get_settings('product_contentshipping') ) }}
								</div>
								{% endif %}

								{% if product_tabtitle %}
								<div class="tab-pane " id="tab-customhtml">{{ product_tabcontent }}</div>
								{% endif %}								
		
								{# --- REPLACE the original simple list with a table when key:value data is detected --- #}
								{% if has_specs %}
								  {# Build headers from attribute.text key names (semicolon-separated "Key: Value" parts) #}
								  {% set headers = [] %}
								  {% for attribute_group in attribute_groups %}
								    {% for attribute in attribute_group.attribute %}
								      {% set parts = attribute.text|default('')|split(';') %}
								      {% for p in parts %}
								        {% set pair = p|trim %}
								        {% if pair %}
								          {% set kv = pair|split(':', 2) %}
								          {% set k = kv[0]|trim %}
								          {% if k and k not in headers %}
								            {% set headers = headers|merge([k]) %}
								          {% endif %}
								        {% endif %}
								      {% endfor %}
								    {% endfor %}
								  {% endfor %}

								  {% if headers|length > 0 %}
								    <div class="tab-pane" id="tab-specs">
								     
								      <div class="table-responsive product-specs-table">
								        <table style="width:100%;border-right:1px solid #ddd;border-bottom:1px solid #ddd;">
								          <thead>
								            <tr>
								              <td class="main-heading">{{ 'Size' }}</td>
								              {% for h in headers %}
								                <td class="main-heading">{{ h }}</td>
								              {% endfor %}
								            </tr>
								          </thead>
								          <tbody>
								            {% for attribute_group in attribute_groups %}
								              {% for attribute in attribute_group.attribute %}
								                {% set map = {} %}
								                {% for p in attribute.text|default('')|split(';') %}
								                  {% set pair = p|trim %}
								                  {% if pair %}
								                    {% set kv = pair|split(':', 2) %}
								                    {% set key = kv[0]|trim %}
								                    {% set val = kv[1]|default('')|trim %}
								                    {% if key %}
								                      {% set map = map|merge({ (key): val }) %}
								                    {% endif %}
								                  {% endif %}
								                {% endfor %}
								                <tr>
								                  <td class="left-heading first">{{ attribute.name }}</td>
								                {% for h in headers %}
<td class="left-heading specs{% if loop.last %} last{% endif %}">{{ map[h]|default('') }}</td>
{% endfor %}
								                </tr>
								              {% endfor %}
								            {% endfor %}
								          </tbody>
								        </table>
								      </div>
								    </div>
								  {% else %}
								    {# Fallback: render the original list if no key:value pairs found #}
								    <div class="tab-pane" id="tab-specs">
								      <h3 class="product-property-title">{{ text_product_specifics|default('Specifications') }}</h3>
								      <ul class="product-property-list util-clearfix">
								        {% for attribute_group in attribute_groups %}
								          {% for attribute in attribute_group.attribute %}
								            <li class="property-item">
								              <span class="propery-title">{{ attribute.name }}</span>
								              <span class="propery-des">{{ attribute.text }}</span>
								            </li>
								          {% endfor %}
								        {% endfor %}
								      </ul>
								    </div>
								  {% endif %}
								{% endif %}
								
							</div>
						</div>
					</div>
				</div>
				
				{#====  Related_Product==== #}
				{% if products and soconfig.get_settings('related_status') %}
				<div class="content-product-bottom clearfix">
					<ul class="nav nav-tabs">
					  <h4 class="title-category">Other Products You May Be Interested In</h4> 
					</ul>
					<div class="tab-content">
					  	<div id="product-related" class="tab-pane fade in active">
							{% include theme_directory~'/template/soconfig/related_product.twig' %}
					  	</div>
					  	
					</div>
					
				</div>
				{% endif %}

				
			</div>
			{#====  Column Right inside==== #}
			{% if col_position== 'inside' %} {{ column_right }} {% endif %}

		</div>
		
    	
    </div>
    {#====  Column Right outside==== #}
    {% if col_position== 'outside' %} {{ column_right }} {% endif %}
    </div>
</div>

<script type="text/javascript">
<!--
$('select[name=\'recurring_id\'], input[name="quantity"]').change(function(){
	$.ajax({
		url: 'index.php?route=product/product/getRecurringDescription',
		type: 'post',
		data: $('input[name=\'product_id\'], input[name=\'quantity\'], select[name=\'recurring_id\']'),
		dataType: 'json',
		beforeSend: function() {
			$('#recurring-description').html('');
		},
		success: function(json) {
			$('.alert-dismissible, .text-danger').remove();


			if (json['success']) {
				$('#recurring-description').html(json['success']);
			}
		}
	});
});
//--></script>






















<script type="text/javascript"><!--
$('#button-cart1').on('click', function() {
	
	$.ajax({
		url: 'index.php?route=extension/soconfig/cart/add',
		type: 'post',
		data: $('#product input[type=\'text\'], #product input[type=\'hidden\'], #product input[type=\'radio\']:checked, #product input[type=\'checkbox\']:checked, #product select, #product textarea'),
		dataType: 'json',
		beforeSend: function() {
			$('#button-cart1').button('loading');
		},
		complete: function() {
			$('#button-cart1').button('reset');
		},
		success: function(json) {
			$('.alert').remove();
			$('.text-danger').remove();
			$('.form-group').removeClass('has-error');
			if (json['error']) {
				if (json['error']['option']) {
					for (i in json['error']['option']) {
						var element = $('#input-option1' + i.replace('_', '-'));
						
						if (element.parent().hasClass('input-group')) {
							element.parent().after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
						} else {
							element.after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
						}
					}
				}
				
				if (json['error']['recurring']) {
					$('select[name=\'recurring_id\']').after('<div class="text-danger">' + json['error']['recurring'] + '</div>');
				}
				
				// Highlight any found errors
				$('.text-danger').parent().addClass('has-error');
			}
			
			if (json['success']) {
				$('.text-danger').remove();
				$('#wrapper').before('<div class="alert alert-success"><i class="fa fa-check-circle"></i> ' + json['success'] + ' <button type="button" class="fa fa-close close" data-dismiss="alert"></button></div>');
				$('#cart  .total-shopping-cart ').html(json['total'] );
				$('#cart > ul').load('index.php?route=common/cart/info ul li');
				
				timer = setTimeout(function () {
					$('.alert').addClass('fadeOut');
				}, 4000);
				$('.so-groups-sticky .popup-mycart .popup-content').load('index.php?route=extension/module/so_tools/info .popup-content .cart-header');
			}
			
		
		},
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
	});
});


//--></script> 
<script type="text/javascript"><!--
$('#button-cart').on('click', function() {
	
	$.ajax({
		url: 'index.php?route=extension/soconfig/cart/add',
		type: 'post',
		data: $('#product input[type=\'text\'], #product input[type=\'hidden\'], #product input[type=\'radio\']:checked, #product input[type=\'checkbox\']:checked, #product select, #product textarea'),
		dataType: 'json',
		beforeSend: function() {
			$('#button-cart').button('loading');
		},
		complete: function() {
			$('#button-cart').button('reset');
		},
		success: function(json) {
			$('.alert').remove();
			$('.text-danger').remove();
			$('.form-group').removeClass('has-error');
			if (json['error']) {
				if (json['error']['option']) {
					for (i in json['error']['option']) {
						var element = $('#input-option' + i.replace('_', '-'));
						
						if (element.parent().hasClass('input-group')) {
							element.parent().after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
						} else {
							element.after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
						}
					}
				}
				
				if (json['error']['recurring']) {
					$('select[name=\'recurring_id\']').after('<div class="text-danger">' + json['error']['recurring'] + '</div>');
				}
				
				// Highlight any found errors
				$('.text-danger').parent().addClass('has-error');
			}
			
			if (json['success']) {
				$('.text-danger').remove();
				$('#wrapper').before('<div class="alert alert-success"><i class="fa fa-check-circle"></i> ' + json['success'] + ' <button type="button" class="fa fa-close close" data-dismiss="alert"></button></div>');
				$('#cart  .total-shopping-cart ').html(json['total'] );
				$('#cart > ul').load('index.php?route=common/cart/info ul li');
				
				timer = setTimeout(function () {
					$('.alert').addClass('fadeOut');
				}, 4000);
				$('.so-groups-sticky .popup-mycart .popup-content').load('index.php?route=extension/module/so_tools/info .popup-content .cart-header');
			}
			
		
		},
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
	});
});


//--></script> 

<script type="text/javascript"><!--
$('.date').datetimepicker({
	language: document.cookie.match(new RegExp('language=([^;]+)'))[1],
	pickTime: false
});

$('.datetime').datetimepicker({
	language: document.cookie.match(new RegExp('language=([^;]+)'))[1],
	pickDate: true,
	pickTime: true
});

$('.time').datetimepicker({
	language: document.cookie.match(new RegExp('language=([^;]+)'))[1],
	pickDate: false
});

$('button[id^=\'button-upload\']').on('click', function() {
	var node = this;

	$('#form-upload').remove();

	$('body').prepend('<form enctype="multipart/form-data" id="form-upload" style="display: none;"><input type="file" name="file" /></form>');

	$('#form-upload input[name=\'file\']').trigger('click');

	if (typeof timer != 'undefined') {
		clearInterval(timer);
	}

	timer = setInterval(function() {
		if ($('#form-upload input[name=\'file\']').val() != '') {
			clearInterval(timer);

			$.ajax({
				url: 'index.php?route=tool/upload',
				type: 'post',
				dataType: 'json',
				data: new FormData($('#form-upload')[0]),
				cache: false,
				contentType: false,
				processData: false,
				beforeSend: function() {
					$(node).button('loading');
				},
				complete: function() {
					$(node).button('reset');
				},
				success: function(json) {
					$('.text-danger').remove();

					if (json['error']) {
						$(node).parent().find('input').after('<div class="text-danger">' + json['error'] + '</div>');
					}

					if (json['success']) {
						alert(json['success']);

						$(node).parent().find('input').val(json['code']);
					}
				},
				error: function(xhr, ajaxOptions, thrownError) {
					alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
				}
			});
		}
	}, 500);
});
//--></script> 
<script type="text/javascript"><!--
$('#review').delegate('.pagination a', 'click', function(e) {
    e.preventDefault();

    $('#review').fadeOut('slow');
    $('#review').load(this.href);
    $('#review').fadeIn('slow');
});

$('#review').load('index.php?route=product/product/review&product_id={{ product_id }}');

$('#button-review').on('click', function() {
	$.ajax({
		url: 'index.php?route=product/product/write&product_id={{ product_id }}',
		type: 'post',
		dataType: 'json',
		data: $("#form-review").serialize(),
		beforeSend: function() {
			$('#button-review').button('loading');
		},
		complete: function() {
			$('#button-review').button('reset');
		},
		success: function(json) {
			$('.alert-dismissible').remove();

			if (json['error']) {
				$('#review').after('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> ' + json['error'] + '</div>');
			}

			if (json['success']) {
				$('#review').after('<div class="alert alert-success alert-dismissible"><i class="fa fa-check-circle"></i> ' + json['success'] + '</div>');

				$('input[name=\'name\']').val('');
				$('textarea[name=\'text\']').val('');
				$('input[name=\'rating\']:checked').prop('checked', false);
			}
		}
	});
});


//--></script>





<script type="text/javascript"><!--
	$(document).ready(function() {
		
		// Initialize the sticky scrolling on an item 
		sidebar_sticky = '{{sidebar_sticky}}';
		
		if(sidebar_sticky=='left'){
			$(".left_column").stick_in_parent({
			    offset_top: 10,
			    bottoming   : true
			});
		}else if (sidebar_sticky=='right'){
			$(".right_column").stick_in_parent({
			    offset_top: 10,
			    bottoming   : true
			});
		}else if (sidebar_sticky=='all'){
			$(".content-aside").stick_in_parent({
			    offset_top: 10,
			    bottoming   : true
			});
		}
		

		$("#thumb-slider .image-additional").each(function() {
			$(this).find("[data-index='0']").addClass('active');
		});
		
		$('.product-options li.radio').click(function(){
			$(this).addClass(function() {
				if($(this).hasClass("active")) return "";
				return "active";
			});
			
			$(this).siblings("li").removeClass("active");
			$(this).parent().find('.selected-option').html('<span class="label label-success">'+ $(this).find('img').data('original-title') +'</span>');
		})
		
		$('.thumb-video').magnificPopup({
		  type: 'iframe',
		  iframe: {
			patterns: {
			   youtube: {
				  index: 'youtube.com/', // String that detects type of video (in this case YouTube). Simply via url.indexOf(index).
				  id: 'v=', // String that splits URL in a two parts, second part should be %id%
				  src: '//www.youtube.com/embed/%id%?autoplay=1' // URL that will be set as a source for iframe. 
					},
				}
			}
		});
	});
//--></script>


<script type="text/javascript">
<!--
var ajax_price = function() {
	$.ajax({
		type: 'POST',
		url: 'index.php?route=extension/soconfig/liveprice/index',
		data: $('.product-detail input[type=\'text\'], .product-detail input[type=\'hidden\'], .product-detail input[type=\'radio\']:checked, .product-detail input[type=\'checkbox\']:checked, .product-detail select, .product-detail textarea'),
		dataType: 'json',
			success: function(json) {
			if (json.success) {
				change_price('#price-special', json.new_price.special);
				change_price('#price-tax', json.new_price.tax);
				change_price('#price-old', json.new_price.price);
			}
		}
	});
}

var change_price = function(id, new_price) {$(id).html(new_price);}
$('.product-detail input[type=\'text\'], .product-detail input[type=\'hidden\'], .product-detail input[type=\'radio\'], .product-detail input[type=\'checkbox\'], .product-detail select, .product-detail textarea, .product-detail input[name=\'quantity\']').on('change', function() {
	ajax_price();
});
//-->
</script>

<script type="text/javascript">
<!--
   $(window).scroll(function() {

    if ($(this).scrollTop()<900)
     {
        $('.ShowHide').fadeOut();
     }
    else 
     {
      $('.ShowHide').fadeIn();
     }
 });
//-->
</script>
<script type="text/javascript">
<!--
$(document).ready(function(){
      $(".content-product-bottom").mouseenter(function() {
          $('.owl2-controls').show();
      });
     $(".content-product-bottom").mouseleave(function() {
                $('.owl2-controls').hide();
              });
});
--></script> 
<script>
// One-time option initializer:
// - Some theme scripts reset options to blank after page load.
// - We select the first value for each option group ONLY if nothing is selected.
// - We never override once the user interacts.
(function (window, $) {
  if (!$) return;
  if (window.BG_OPTION_INIT_LOADED) return;
  window.BG_OPTION_INIT_LOADED = true;

  var userTouched = false;
  function markTouched(e) {
    // Only treat real user actions as "touched"
    try { if (e && e.isTrusted === false) return; } catch (x) {}
    userTouched = true;
  }
  // Themes often use custom clickable spans; watch the whole #product block.
  $(document).on('pointerdown mousedown touchstart keydown', '#product', function (e) {
    var t = e && e.target ? e.target : null;
    if (!t) return;
    // Native controls or their wrappers
    if ($(t).closest('select[name^="option["], input[name^="option["], .option-content-box, label').length) {
      markTouched(e);
    }
  });
  // Also stop auto-init once user makes a real change selection.
  $(document).on('change', '#product select[name^="option["], #product input[name^="option["]', function (e) {
    markTouched(e);
  });

  function initOnce() {
    if (userTouched) return;

    // SELECT: if empty, pick first non-empty option
    $('#product select[name^="option["]').each(function () {
      var $sel = $(this);
      if ($sel.val()) return;
      var $opt = $sel.find('option').filter(function () { return $(this).val() !== ''; }).first();
      if ($opt.length) {
        $sel.val($opt.val());
        try { $sel.trigger('change'); } catch (e) {}
      }
    });

    // RADIO: for each group, if none checked, check first
    var radioNames = {};
    $('#product input[type="radio"][name^="option["]').each(function () {
      radioNames[$(this).attr('name')] = true;
    });
    for (var rn in radioNames) {
      if (!Object.prototype.hasOwnProperty.call(radioNames, rn)) continue;
      var $group = $('#product input[type="radio"][name="' + rn.replace(/"/g, '\\"') + '"]');
      if (!$group.length) continue;
      if ($group.filter(':checked').length) continue;
      var $first = $group.first();
      if ($first.length) {
        $group.prop('checked', false);
        $first.prop('checked', true);
        try { $first.trigger('change'); } catch (e) {}
      }
    }

    // CHECKBOX: if none checked in a container, check first
    $('#product [id^="input-option"]').each(function () {
      var $c = $(this);
      var $checks = $c.find('input[type="checkbox"][name^="option["]');
      if (!$checks.length) return;
      if ($checks.filter(':checked').length) return;
      var $firstCb = $checks.first();
      if ($firstCb.length) {
        $firstCb.prop('checked', true);
        try { $firstCb.trigger('change'); } catch (e) {}
      }
    });
  }

  // Run a few times to beat theme resets, then stop.
  $(function () {
    setTimeout(initOnce, 0);
    setTimeout(initOnce, 300);
    setTimeout(initOnce, 1200);
  });
})(window, window.jQuery);
</script>
{# Removed: JS that prevented clicking out-of-stock options. #}

<!-- (the rest of your original scripts remain unchanged) -->

{# --- BEGIN added BG POA frontend composition script (safe, non-destructive) --- #}
<script type="text/javascript">
(function(){
    // This inline script is legacy and relies on option_value_id-based keys.
    // The repo now ships `catalog/view/javascript/bg_variants.js` + `product/product_variant` endpoint.
    // Keep this disabled unless explicitly enabled for debugging.
    try { if (window.bgVariantUseInline !== true) return; } catch(e) { return; }
    // Defensive initial maps (controller provides JSON strings)
    var BG_WH_MAP = {};
    var BG_STATUS_MAP = {};
    var OPTION_QTY_MAP = {};
    var PRODUCT_QTY = 0;

    // Variant map keyed by option_key (e.g. "563|571|574") -> variant object
    var BG_VARIANTS_MAP = {};

    try { BG_WH_MAP = {{ bg_wh_map_js|default('{}')|raw }}; } catch(e){ BG_WH_MAP = {}; }
    try { BG_STATUS_MAP = {{ bg_status_map_js|default('{}')|raw }}; } catch(e){ BG_STATUS_MAP = {}; }
    try { OPTION_QTY_MAP = {{ option_qty_map_json|default('{}')|raw }}; } catch(e){ OPTION_QTY_MAP = {}; }
    try { PRODUCT_QTY = parseInt({{ product_quantity|default(0) }}, 10) || 0; } catch(e){ PRODUCT_QTY = 0; }

    // Build variants map lazily (bg_variants is set later in the template)
    function buildVariantsMap() {
        try {
            var list = (typeof bg_variants !== 'undefined') ? bg_variants : (window.bgVariants || []);
            if (Array.isArray(list)) {
                for (var i=0;i<list.length;i++){
                    var v = list[i];
                    if (v && v.option_key) {
                        // Normalize option_key (ensure string)
                        var key = String(v.option_key);
                        BG_VARIANTS_MAP[key] = v;
                    }
                }
            }
        } catch(e){}
    }

    function sanitizeSupplierText(text) {
        if (!text) return '';
        text = String(text).trim();
        text = text.replace(/\s*\(\s*\d+\s*\)\s*$/,'');
        text = text.replace(/\s*\b\d+\s*(in stock|pcs|pieces|units)?\s*$/i,'');
        text = text.replace(/\s*\b\d+\s*$/,'');
        return text.trim();
    }

    // Prefer option_value_id (data-ov) where available — fallback to product_option_value_id
    function collectSelectedOptionValueIds() {
        var ids = [];
        $('select[name^="option["]').each(function() {
            // prefer option_value_id from the selected <option data-ov="...">
            var ov = $(this).find('option:selected').data('ov');
            if (ov !== undefined && ov !== null && ov !== '') {
                ids.push(String(ov));
            } else {
                var pov = $(this).val();
                if (pov) ids.push(String(pov));
            }
        });
        $('input[type="radio"][name^="option["]:checked, input[type="checkbox"][name^="option["]:checked').each(function(){
            // prefer data-ov attribute on the input
            var ov = $(this).data('ov');
            if (ov !== undefined && ov !== null && ov !== '') {
                ids.push(String(ov));
            } else {
                var pov = $(this).val();
                if (pov) ids.push(String(pov));
            }
        });
        // remove duplicates while preserving order
        return ids.filter(function(v,i,a){ return a.indexOf(v) === i; });
    }

    function lookupNumericQtyForId(id) {
        if (!id) return null;
        var povKey = 'pov_' + String(id);
        if (OPTION_QTY_MAP.hasOwnProperty(povKey)) {
            var v = parseInt(OPTION_QTY_MAP[povKey], 10);
            if (!isNaN(v)) return v;
        }
        if (OPTION_QTY_MAP.hasOwnProperty(String(id))) {
            var v2 = parseInt(OPTION_QTY_MAP[String(id)], 10);
            if (!isNaN(v2)) return v2;
        }
        try {
            var $byValue = $('#product').find('[value="' + id + '"]').first();
            if ($byValue && $byValue.length) {
                var attr = $byValue.attr('data-qty');
                if (attr === undefined || attr === null) attr = $byValue.data('qty');
                if (attr !== undefined && attr !== null && attr !== '') {
                    var v3 = parseInt(attr, 10);
                    if (!isNaN(v3)) return v3;
                }
            }
            var $byOv = $('#product').find('[data-ov="' + id + '"]').first();
            if ($byOv && $byOv.length) {
                var attr2 = $byOv.attr('data-qty');
                if (attr2 === undefined || attr2 === null) attr2 = $byOv.data('qty');
                if (attr2 !== undefined && attr2 !== null && attr2 !== '') {
                    var v4 = parseInt(attr2, 10);
                    if (!isNaN(v4)) return v4;
                }
            }
        } catch(e){}
        return null;
    }

    function getFirstBgTextForIds(ids) {
        if (!ids || !ids.length) return '';
        for (var idx = 0; idx < ids.length; idx++) {
            var id = ids[idx];
            if (!id) continue;
            if (BG_WH_MAP && (BG_WH_MAP[id] !== undefined)) {
                var val = BG_WH_MAP[id];
                if (Array.isArray(val)) {
                    if (val.length) return String(val[0]);
                } else if (String(val).trim() !== '') {
                    return String(val);
                }
            }
            if (BG_STATUS_MAP && (BG_STATUS_MAP[id] !== undefined)) {
                var s = BG_STATUS_MAP[id];
                if (Array.isArray(s)) {
                    if (s.length) return String(s[0]);
                } else if (String(s).trim() !== '') {
                    return String(s);
                }
            }
        }
        return '';
    }

    function composeHtmlForSelection() {
        var ids = collectSelectedOptionValueIds();

        // Ensure variants map is built before we try to use it
        if (!BG_VARIANTS_MAP || Object.keys(BG_VARIANTS_MAP).length === 0) {
            buildVariantsMap();
        }

        // Try to detect a full combination variant first (prefer variant-level quantity)
        if (ids && ids.length) {
            // Build canonical option_key (sorted numeric ids joined by '|')
            try {
                var normalized = ids.map(function(x){ return parseInt(x,10) || 0; }).filter(function(n){ return n>0; });
                normalized.sort(function(a,b){ return a-b; });
                var combKey = normalized.join('|');
                if (combKey && BG_VARIANTS_MAP[combKey]) {
                    var variant = BG_VARIANTS_MAP[combKey];
                    var vqty = null;
                    if (variant && typeof variant.quantity !== 'undefined') {
                        vqty = parseInt(variant.quantity,10);
                        if (!isNaN(vqty)) {
                            var supplierRaw = getFirstBgTextForIds(ids);
                            var supplierClean = sanitizeSupplierText(supplierRaw);
                            if (vqty > 0) {
                                if (supplierClean && supplierClean.trim() !== '') {
                                    return supplierClean + ' ' + vqty + ' in stock';
                                }
                                return vqty + ' in stock';
                            } else {
                                // explicit sold out for the combination
                                if (supplierClean && supplierClean.trim() !== '') {
                                    return supplierClean + ' - Sold out';
                                }
                                return 'Sold out';
                            }
                        }
                    }
                }
            } catch(e){}
        }

        // If no combination variant matched, fall back to per-id numeric logic (existing behavior)
        if (!ids.length) {
            var supplierNone = getFirstBgTextForIds(ids);
            if (PRODUCT_QTY > 0) {
                if (supplierNone && supplierNone.trim() !== '') {
                    var supClean = sanitizeSupplierText(supplierNone);
                    return (supClean ? (supClean + ' ') : '') + PRODUCT_QTY + ' in stock';
                }
                return PRODUCT_QTY + ' in stock';
            }
            return 'Out Of Stock. No Expected Date';
        }
        var numericVals = [];
        for (var i = 0; i < ids.length; i++) {
            var n = lookupNumericQtyForId(ids[i]);
            if (n !== null) numericVals.push(n);
        }
        var supplierRaw = getFirstBgTextForIds(ids);
        var supplierClean = sanitizeSupplierText(supplierRaw);

        if (numericVals.length > 0) {
            var min = numericVals.reduce(function(a,b){ return Math.min(a,b); }, Infinity);
            if (isFinite(min)) {
                if (supplierClean && supplierClean.trim() !== '') {
                    return supplierClean + ' ' + min + ' in stock';
                }
                return min + ' in stock';
            }
        }
        if (supplierRaw && supplierRaw.trim() !== '') return supplierRaw;
        return 'No stock info available for selected options';
    }

    function updatePlaceholder() {
        var $ph = $('#bg-poa-status');
        if (!$ph.length) return;
        $ph.text(composeHtmlForSelection());
    }

    $(document).on('change', 'select[name^="option["], input[type="radio"][name^="option["], input[type="checkbox"][name^="option["]', function(){
        updatePlaceholder();
    });

    $(document).ready(function(){
        updatePlaceholder();
        try {
            var target = document.getElementById('product');
            if (target) {
                var mo = new MutationObserver(function() {
                    setTimeout(updatePlaceholder, 150);
                });
                mo.observe(target, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled','data-available','class','data-qty'] });
            }
        } catch(e){}
    });
})();
</script>

<script> var bg_variants = {{ bg_variants|raw|default('[]') }}; var bg_wh_map_js = {{ bg_wh_map_js|raw|default('{}') }}; var bg_status_map_js = {{ bg_status_map_js|raw|default('{}') }}; var pvid_to_ovid_js = {{ pvid_to_ovid_js|raw|default('{}') }}; var option_to_variant_keys_js = {{ option_to_variant_keys_js|raw|default('{}') }}; </script> <script src="catalog/view/javascript/bg_variants.js"></script>
{{ footer }}
