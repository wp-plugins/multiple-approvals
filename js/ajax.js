(function($){

	// Not related to the ajax functionality, but are some nice UI settings
	$('#mait_progress_bar').hover( 
		function(){ 
			$(this).css({'opacity': '0.5', 'cursor':'pointer'}); 
		},
		function(){
			$(this).css({'opacity': '1', 'cursor':'default'});
		}
	);
	$('#mait_progress_bar').click( 
		function(){
			$('#mait_approval_list').toggle();
		}
	);
	
	// compile necessary data into one variable
	var data_approve = {
		action: 'mait_approve_post',
		postid: mait_params.postid,
		userid: mait_params.userid,
		
	};
	var data_remove = {
		action: 'mait_remove_approval',
		postid: mait_params.postid,
		userid: mait_params.userid
	};
	
	// callback functions

	// handle removing approval of the post 
	function mapit_remove_approval(){
		// add the nonce security to the data variable
		data_remove['nonce'] = $(this).find('a').attr('href');
		// remove the options bar so the user cannot call this function multiple times by repeated clicking 
		$('.mait_options_bar').html('');
		
		// send data
		$.post( mait_params.ajaxurl, data_remove, function(response){
			//receieve data
			var status = $(response).find('mait_remove_approval').attr('id');	
			
			if ( status == 1 ){ // success
				// retrieve data from WP_Ajax_Response object
				var data = $(response).find('response_data').text();
				var status = $(response).find('status').text();
				var approvals = $(response).find('approvals').text();
				
				// update appropiate nodes
				$('#mait_approval_number').text(approvals);
				$('#mait_approval_list').html(data);
				$('.mait_options_bar').replaceWith('<div id="mait_approve" class="mait_options_bar"><strong><a href="'+data_remove['nonce']+'" class="button-primary">Approve</a></strong>');
				$('#mait_progress').css('width',status+'%');
				$('#mait_approve').click( mait_approve );
			}
			else{
				$('.mait_options_bar').html('error');
			}
		});
		// cancel default browser behavior (should I not do this?)
		return false;
	}
	
	// handle approving the post
	function mait_approve(){
		// add the nonce security to the data variable
		data_approve['nonce'] = $(this).find('a').attr('href');
		// remove the options bar so the user cannot call this function multiple times by repeated clicking 
		$('.mait_options_bar').html('');
		
		// send data
		$.post( mait_params.ajaxurl, data_approve, function(response){
			//receieve data
			var status = $(response).find('mait_approve_post').attr('id');		
			
			if ( status == 1 ){ // success
				// retrieve data from WP_Ajax_Response object
				var data = $(response).find('response_data').text();
				var status = $(response).find('status').text();
				var approvals = $(response).find('approvals').text();
				
				// update appropiate nodes
				$('#mait_approval_number').text(approvals);
				$('#mait_approval_list').html(data);
				$('#mait_progress').css('width',status+'%');
				
				// special case: this approval statisfies the approval requirement
				if ( status == 100 ){
					$('#mait_progress').text('Published');
				}
				else{
					$('.mait_options_bar').replaceWith('<div id="mait_remove_approval" class="submitbox mait_options_bar"><a class="submitdelete" href="'+data_approve['nonce']+'">Remove your approval</a></div>');
					$('#mait_remove_approval').click( mapit_remove_approval );
				}
			}
			else{
				$('.mait_options_bar').html('error');
			}
		});
		// cancel default browser behavior (should I not do this?)
		return false;
	}
	
	// add these callback functions to the button/link
	$('#mait_approve').click( mait_approve );
	$('#mait_remove_approval').click( mapit_remove_approval );
	
})(jQuery)