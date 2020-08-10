<?php
/*
	File-Name:		email-validator.inc.php
	Author:			nepomuk (hendrik.sgries@get-the-code.de)
	Description:	class to check, if an email-address exists
	Date:			   2003
*/



/*******************************/
/*** global static constants ***/
/*******************************/

	/*** Return-Constants ***/

	define ( "INVALID_MAIL",			-10 ) ;
	define ( "UNKNOWN_SERVER",			-20 ) ;
	define ( "SYNTAX_INCORRECT",		-30 ) ;
	define ( "CONNECTION_FAILED",		-40 ) ;
	define ( "REQUEST_REJECTED",		-50 ) ;

	define ( "VALID_MAIL",				10 ) ;
	define ( "SYNTAX_CORRECT",			20 ) ;






class EMailValidator
{


	function validateEMailAddress( $strEMailAddress )
	{
		$strEMailAddress = trim( $strEMailAddress ) ;

		if( !$this -> checkSyntax( $strEMailAddress ) )
			return SYNTAX_INCORRECT ;

		$strHostName = $this -> extractHostName( $strEMailAddress ) ;

		if( !$this -> checkHostName( $strHostName ) )
			return UNKNOWN_SERVER ;

		return $this -> checkEMailAddress( $strHostName, $strEMailAddress ) ;
	}




	function checkSyntax( &$strEMailAddress )
	{
		return preg_match( "/^[0-9a-z_]([-_\.]*[0-9a-z])*\+?[0-9a-z]*([-_\.]*[0-9a-z])*@[0-9a-z]([-\._]*[0-9a-z])*\\.[a-z]{2,24}$/i", $strEMailAddress  ) == 1 ;
	}


	function extractHostName( &$strEMailAddress )
	{
		$arrElements = explode( "@", $strEMailAddress ) ;
		return $arrElements[ 1 ] ;
	}


	function checkHostName( &$strHostName )
	{
		if ( preg_match( "/^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$/", $strHostName ) )
			$numHostIP = @gethostbyaddr ( $strHostName ) ;
		else
			$numHostIP = @gethostbyname ( $strHostName ) ;
		return strlen( $numHostIP ) > 6 && $numHostIP != $strHostName && $numHostIP != "www." . $strHostName ;
	}


	function getMailExchanger( &$strHostName )
	{
		$arrMXHosts = array( ) ;
		$arrHostsWeight = array( ) ;
		$blnHasMXHosts = @getmxrr ( $strHostName, $arrMXHosts, $arrHostsWeight ) ;
		if( !$blnHasMXHosts )
			$arrMXHosts[ 0 ] = $strHostName ;
		else if( sizeof( $arrMXHosts ) > 1 )
			$this -> sortByKey( $arrMXHosts, $arrHostsWeight ) ;
		return $arrMXHosts ;
	}



	function checkEMailAddress( $strHostName,
								$strEMailAddress )
	{
		$arrMXHosts = $this -> getMailExchanger( $strHostName ) ;

		// iterate through the mail-server ( MX ) -names and send an request
		// to check, if given email-Adress exists
		// if the establishing of a connection failed, try the next one
		for ( $i=0; $i < sizeof( $arrMXHosts ); $i++ )
		{
			$fpMailServer = $this -> connectToMailServer( $arrMXHosts[ $i ] ) ;

			if( $fpMailServer == CONNECTION_FAILED )	// connection failed
				$blnConnect = FALSE ;
			else	// successful connected
			{
				$blnConnect = TRUE ;
				$numEMailExists = $this -> sendMailRequest( $fpMailServer, $strEMailAddress ) ;
				if( $numEMailExists != CONNECTION_FAILED && $numEMailExists != REQUEST_REJECTED )
				break ;
			}
		}

		if ( !$blnConnect )	// connection to smtp-service failed
			return SYNTAX_CORRECT ;
		else
			return $numEMailExists ;
	}



	/*** Ask the smtp-server, if given email-address exists ***/

	function sendMailRequest( $fpMailServer,
							  $strMailRecipient )
	{
		$SERVER_NAME = getenv( "SERVER_NAME" ) ;

		// check, if server is ready to accept smtp-commands ( Return-Code: 220 )

		$strAnswer = @fgets( $fpMailServer, 3000 ) ;
		if( strlen( $strAnswer ) == 0 ) // no answer
		{
			$this -> closeConnection( $fpMailServer, FALSE ) ;
			return CONNECTION_FAILED ;
		}
		else if( !preg_match( "/^220/", $strAnswer ) ) // request rejected
		{
			$this -> closeConnection( $fpMailServer, FALSE ) ;
			return REQUEST_REJECTED ;
		}

		// say hi ( Return-Code: 250 )

		@fwrite ( $fpMailServer, "HELO " . $SERVER_NAME . "\n" ) ;

		$strAnswer = @fgets( $fpMailServer, 3000 ) ;
		
		if( !preg_match( "/^250/", $strAnswer ) ) // request rejected ( bad client-host?? )
		{
			$this -> closeConnection( $fpMailServer ) ;
			return REQUEST_REJECTED ;
		}

		// tell the server, who wants to send the mail ( Return-Code: 250 )
		@fwrite ( $fpMailServer, "MAIL FROM: <kunibert@" . $SERVER_NAME . ">\n" ) ;

		$strAnswer = @fgets( $fpMailServer, 1500 ) ;

		if( !preg_match( "/^250/", $strAnswer ) ) // REQUEST_REJECTED
		{
			$this -> closeConnection( $fpMailServer ) ;
			return REQUEST_REJECTED ;
		}

		// tell the server, who is the mail-recipient ( Return-Code: 250 )
		// if the recipient is unknown, the mail-address is invalid
		@fwrite ( $fpMailServer, "RCPT TO: <" . $strMailRecipient . ">\n" ) ;

		$strAnswer = @fgets( $fpMailServer, 1500 ) ;
       
        if( !preg_match( "/^250/", $strAnswer ) ) // recipient unknown
		{
			$this -> closeConnection( $fpMailServer ) ;
			return INVALID_MAIL ;
		}

		// say goodbye
		$this -> closeConnection( $fpMailServer ) ;

		// no error occured, so the mail-address is valid
		return VALID_MAIL ;
	}



	// Opens a socket-connection to smtp-mail-service
	// try max. 5 times, if connection failed, return CONNECTION_FAILED

	function connectToMailServer( $strMXHost )
	{
		for ( $i = 0; $i < 5; $i++ )
		{
			// open an socket-connection at tcp-port 25 ( default mail-port )
			$fpMailServer = @fsockopen ( $strMXHost, 25, $errno, $errstr, 100 );	// $errno and $errstr currently not used

			if( $fpMailServer )
			{
				// stream should be closed after 1 second, PHP >= PHP 4.3
				$strPHPVersion = phpversion( ) ;
				if( preg_match( "/^(4\.[3-9])/", $strPHPVersion ) || $strPHPVersion{ 0 } == '5' )
					@stream_set_timeout(  $fpMailServer, 1  ) ;
				return $fpMailServer ;	// successful connected
			}
		}
		return CONNECTION_FAILED ;	// connection failed
	}


	// Closes an open socket-connection
	function closeConnection( $fpConnection,
							  $bStarted = TRUE )
	{
		if( $bStarted )
			@fwrite ( $fpConnection, "QUIT\n" ) ;
		@fclose ( $fpConnection ) ;
	}



	// a simple bubble-sort-implementation
	// sorts first the key-array in ascending order
	// and then the array given with the first parameter
	// in the order of the key-array
	function sortByKey( &$objArray,
						&$arrKey )
	{
		$numEnd = sizeof( $objArray ) -1 ;
		$numEnd = sizeof( $objArray ) -1 ;
		for ( $i = 1; $i <= $numEnd; $i++ )
		{
			for ( $j = $numEnd; $j >= $i; $j-- )
			{
				if ( $arrKey[ $j - 1 ] > $arrKey[ $j ] )
				{
					$numBuffer[ 0 ] = $arrKey[ $j ] ;
					$numBuffer[ 1 ] = $objArray[ $j ] ;
					$arrKey[ $j ] = $arrKey[ $j - 1 ] ;
					$objArray[ $j ] = $objArray[ $j - 1 ] ;
					$arrKey[ $j - 1 ] = $numBuffer[ 0 ] ;
					$objArray[ $j - 1 ] = $numBuffer[ 1 ] ;
				}
			}
		}
	}



	// counts the number of occurances of a given char
	// in a given string
	function countChars( &$strString,
						 $charSeparator )
	{
		$arrSplit = explode( $charSeparator, $strString ) ;
		return sizeof( $arrSplit ) - 1 ;
	}


}


?>
