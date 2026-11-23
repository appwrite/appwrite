





Network Working Group                                       G. Vaudreuil
Request for Comments: 3463                           Lucent Technologies
Obsoletes: 1893                                             January 2003
Category: Standards Track


                   Enhanced Mail System Status Codes

Status of this Memo

   This document specifies an Internet standards track protocol for the
   Internet community, and requests discussion and suggestions for
   improvements.  Please refer to the current edition of the "Internet
   Official Protocol Standards" (STD 1) for the standardization state
   and status of this protocol.  Distribution of this memo is unlimited.

Copyright Notice

   Copyright (C) The Internet Society (2003).  All Rights Reserved.

Abstract

   This document defines a set of extended status codes for use within
   the mail system for delivery status reports, tracking, and improved
   diagnostics.  In combination with other information provided in the
   Delivery Status Notification (DSN) delivery report, these codes
   facilitate media and language independent rendering of message
   delivery status.

Table of Contents

   1.   Overview ......................................................2
   2.   Status Code Structure .........................................3
   3.   Enumerated Status Codes .......................................5
     3.1  Other or Undefined Status ...................................6
     3.2  Address Status ..............................................6
     3.3  Mailbox Status ..............................................7
     3.4  Mail system status ..........................................8
     3.5  Network and Routing Status ..................................9
     3.6  Mail Delivery Protocol Status ..............................10
     3.7  Message Content or Message Media Status ....................11
     3.8  Security or Policy Status ..................................12
   4.   References ...................................................13
   5.   Security Considerations ......................................13
        Appendix A - Collected Status Codes ..........................14
        Appendix B - Changes from RFC1893 ............................15
        Author's Address .............................................15
        Full Copyright Statement .....................................16



Vaudreuil                   Standards Track                     [Page 1]

RFC 3463           Enhanced Mail System Status Codes        January 2003


1. Overview

   There is a need for a standard mechanism for the reporting of mail
   system errors richer than the limited set offered by SMTP and the
   system specific text descriptions sent in mail messages.  There is a
   pressing need for a rich machine-readable, human language independent
   status code for use in delivery status notifications [DSN].  This
   document proposes a new set of status codes for this purpose.

   SMTP [SMTP] error codes have historically been used for reporting
   mail system errors.  Because of limitations in the SMTP code design,
   these are not suitable for use in delivery status notifications.
   SMTP provides about 12 useful codes for delivery reports.  The
   majority of the codes are protocol specific response codes such as
   the 354 response to the SMTP data command.  Each of the 12 useful
   codes are overloaded to indicate several error conditions.  SMTP
   suffers some scars from history, most notably the unfortunate damage
   to the reply code extension mechanism by uncontrolled use.  This
   proposal facilitates future extensibility by requiring the client to
   interpret unknown error codes according to the theory of codes while
   requiring servers to register new response codes.

   The SMTP theory of reply codes are partitioned in the number space in
   such a manner that the remaining available codes will not provide the
   space needed.  The most critical example is the existence of only 5
   remaining codes for mail system errors.  The mail system
   classification includes both host and mailbox error conditions.  The
   remaining third digit space would be completely consumed as needed to
   indicate MIME and media conversion errors and security system errors.

   A revision to the SMTP theory of reply codes to better distribute the
   error conditions in the number space will necessarily be incompatible
   with SMTP.  Further, consumption of the remaining reply-code number
   space for delivery notification reporting will reduce the available
   codes for new ESMTP extensions.

   The following status code set is based on the SMTP theory of reply
   codes.  It adopts the success, permanent error, and transient error
   semantics of the first value, with a further description and
   classification in the second.  This proposal re-distributes the
   classifications to better distribute the error conditions, such as
   separating mailbox from host errors.

   Document Conventions

   The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT",
   "SHOULD", "SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this
   document are to be interpreted as described in BCP 14 [RFC2119].



Vaudreuil                   Standards Track                     [Page 2]

RFC 3463           Enhanced Mail System Status Codes        January 2003


2. Status Code Structure

   This document defines a new set of status codes to report mail system
   conditions.  These status codes are used for media and language
   independent status reporting.  They are not intended for system
   specific diagnostics.

   The syntax of the new status codes is defined as:

      status-code = class "." subject "." detail

      class = "2"/"4"/"5"

      subject = 1*3digit

      detail = 1*3digit

   White-space characters and comments are NOT allowed within a status-
   code.  Each numeric sub-code within the status-code MUST be expressed
   without leading zero digits.

   Status codes consist of three numerical fields separated by ".".  The
   first sub-code indicates whether the delivery attempt was successful.
   The second sub-code indicates the probable source of any delivery
   anomalies, and the third sub-code indicates a precise error
   condition.

   Example:  2.1.23

   The code space defined is intended to be extensible only by standards
   track documents.  Mail system specific status codes should be mapped
   as close as possible to the standard status codes.  Servers should
   send only defined, registered status codes.  System specific errors
   and diagnostics should be carried by means other than status codes.

   New subject and detail codes will be added over time.  Because the
   number space is large, it is not intended that published status codes
   will ever be redefined or eliminated.  Clients should preserve the
   extensibility of the code space by reporting the general error
   described in the subject sub-code when the specific detail is
   unrecognized.










Vaudreuil                   Standards Track                     [Page 3]

RFC 3463           Enhanced Mail System Status Codes        January 2003


   The class sub-code provides a broad classification of the status.
   The enumerated values for each class are defined as:

      2.XXX.XXX   Success

         Success specifies that the DSN is reporting a positive delivery
         action.  Detail sub-codes may provide notification of
         transformations required for delivery.

      4.XXX.XXX   Persistent Transient Failure

         A persistent transient failure is one in which the message as
         sent is valid, but persistence of some temporary condition has
         caused abandonment or delay of attempts to send the message.
         If this code accompanies a delivery failure report, sending in
         the future may be successful.

      5.XXX.XXX   Permanent Failure

         A permanent failure is one which is not likely to be resolved
         by resending the message in the current form.  Some change to
         the message or the destination must be made for successful
         delivery.

   A client must recognize and report class sub-code even where
   subsequent subject sub-codes are unrecognized.

   The subject sub-code classifies the status.  This value applies to
   each of the three classifications.  The subject sub-code, if
   recognized, must be reported even if the additional detail provided
   by the detail sub-code is not recognized.  The enumerated values for
   the subject sub-code are:

      X.0.XXX   Other or Undefined Status

         There is no additional subject information available.

      X.1.XXX Addressing Status

         The address status reports on the originator or destination
         address.  It may include address syntax or validity.  These
         errors can generally be corrected by the sender and retried.

      X.2.XXX Mailbox Status

         Mailbox status indicates that something having to do with the
         mailbox has caused this DSN.  Mailbox issues are assumed to be
         under the general control of the recipient.



Vaudreuil                   Standards Track                     [Page 4]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.3.XXX Mail System Status

         Mail system status indicates that something having to do with
         the destination system has caused this DSN.  System issues are
         assumed to be under the general control of the destination
         system administrator.

      X.4.XXX Network and Routing Status

         The networking or routing codes report status about the
         delivery system itself.  These system components include any
         necessary infrastructure such as directory and routing
         services.  Network issues are assumed to be under the control
         of the destination or intermediate system administrator.

      X.5.XXX Mail Delivery Protocol Status

         The mail delivery protocol status codes report failures
         involving the message delivery protocol.  These failures
         include the full range of problems resulting from
         implementation errors or an unreliable connection.

      X.6.XXX Message Content or Media Status

         The message content or media status codes report failures
         involving the content of the message.  These codes report
         failures due to translation, transcoding, or otherwise
         unsupported message media.  Message content or media issues are
         under the control of both the sender and the receiver, both of
         which must support a common set of supported content-types.

      X.7.XXX Security or Policy Status

         The security or policy status codes report failures involving
         policies such as per-recipient or per-host filtering and
         cryptographic operations.  Security and policy status issues
         are assumed to be under the control of either or both the
         sender and recipient.  Both the sender and recipient must
         permit the exchange of messages and arrange the exchange of
         necessary keys and certificates for cryptographic operations.

3. Enumerated Status Codes

   The following section defines and describes the detail sub-code.  The
   detail value provides more information about the status and is
   defined relative to the subject of the status.





Vaudreuil                   Standards Track                     [Page 5]

RFC 3463           Enhanced Mail System Status Codes        January 2003


3.1 Other or Undefined Status

      X.0.0   Other undefined Status

         Other undefined status is the only undefined error code.  It
         should be used for all errors for which only the class of the
         error is known.

3.2 Address Status

      X.1.0   Other address status

         Something about the address specified in the message caused
         this DSN.

      X.1.1   Bad destination mailbox address

         The mailbox specified in the address does not exist.  For
         Internet mail names, this means the address portion to the left
         of the "@" sign is invalid.  This code is only useful for
         permanent failures.

      X.1.2   Bad destination system address

         The destination system specified in the address does not exist
         or is incapable of accepting mail.  For Internet mail names,
         this means the address portion to the right of the "@" is
         invalid for mail.  This code is only useful for permanent
         failures.

      X.1.3   Bad destination mailbox address syntax

         The destination address was syntactically invalid.  This can
         apply to any field in the address.  This code is only useful
         for permanent failures.

      X.1.4   Destination mailbox address ambiguous

         The mailbox address as specified matches one or more recipients
         on the destination system.  This may result if a heuristic
         address mapping algorithm is used to map the specified address
         to a local mailbox name.

      X.1.5   Destination address valid

         This mailbox address as specified was valid.  This status code
         should be used for positive delivery reports.




Vaudreuil                   Standards Track                     [Page 6]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.1.6   Destination mailbox has moved, No forwarding address

         The mailbox address provided was at one time valid, but mail is
         no longer being accepted for that address.  This code is only
         useful for permanent failures.

      X.1.7   Bad sender's mailbox address syntax

         The sender's address was syntactically invalid.  This can apply
         to any field in the address.

      X.1.8   Bad sender's system address

         The sender's system specified in the address does not exist or
         is incapable of accepting return mail.  For domain names, this
         means the address portion to the right of the "@" is invalid
         for mail.

3.3 Mailbox Status

      X.2.0   Other or undefined mailbox status

         The mailbox exists, but something about the destination mailbox
         has caused the sending of this DSN.

      X.2.1   Mailbox disabled, not accepting messages

         The mailbox exists, but is not accepting messages.  This may be
         a permanent error if the mailbox will never be re-enabled or a
         transient error if the mailbox is only temporarily disabled.

      X.2.2   Mailbox full

         The mailbox is full because the user has exceeded a per-mailbox
         administrative quota or physical capacity.  The general
         semantics implies that the recipient can delete messages to
         make more space available.  This code should be used as a
         persistent transient failure.

      X.2.3   Message length exceeds administrative limit

         A per-mailbox administrative message length limit has been
         exceeded.  This status code should be used when the per-mailbox
         message length limit is less than the general system limit.
         This code should be used as a permanent failure.






Vaudreuil                   Standards Track                     [Page 7]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.2.4   Mailing list expansion problem

         The mailbox is a mailing list address and the mailing list was
         unable to be expanded.  This code may represent a permanent
         failure or a persistent transient failure.

3.4  Mail system status

      X.3.0   Other or undefined mail system status

         The destination system exists and normally accepts mail, but
         something about the system has caused the generation of this
         DSN.

      X.3.1   Mail system full

         Mail system storage has been exceeded.  The general semantics
         imply that the individual recipient may not be able to delete
         material to make room for additional messages.  This is useful
         only as a persistent transient error.

      X.3.2   System not accepting network messages

         The host on which the mailbox is resident is not accepting
         messages.  Examples of such conditions include an immanent
         shutdown, excessive load, or system maintenance.  This is
         useful for both permanent and persistent transient errors.

      X.3.3   System not capable of selected features

         Selected features specified for the message are not supported
         by the destination system.  This can occur in gateways when
         features from one domain cannot be mapped onto the supported
         feature in another.

      X.3.4   Message too big for system

         The message is larger than per-message size limit.  This limit
         may either be for physical or administrative reasons.  This is
         useful only as a permanent error.

      X.3.5 System incorrectly configured

         The system is not configured in a manner that will permit it to
         accept this message.






Vaudreuil                   Standards Track                     [Page 8]

RFC 3463           Enhanced Mail System Status Codes        January 2003


3.5 Network and Routing Status

      X.4.0   Other or undefined network or routing status

         Something went wrong with the networking, but it is not clear
         what the problem is, or the problem cannot be well expressed
         with any of the other provided detail codes.

      X.4.1   No answer from host

         The outbound connection attempt was not answered, because
         either the remote system was busy, or was unable to take a
         call.  This is useful only as a persistent transient error.

      X.4.2   Bad connection

         The outbound connection was established, but was unable to
         complete the message transaction, either because of time-out,
         or inadequate connection quality.  This is useful only as a
         persistent transient error.

      X.4.3   Directory server failure

         The network system was unable to forward the message, because a
         directory server was unavailable.  This is useful only as a
         persistent transient error.

         The inability to connect to an Internet DNS server is one
         example of the directory server failure error.

      X.4.4   Unable to route

         The mail system was unable to determine the next hop for the
         message because the necessary routing information was
         unavailable from the directory server.  This is useful for both
         permanent and persistent transient errors.

         A DNS lookup returning only an SOA (Start of Administration)
         record for a domain name is one example of the unable to route
         error.

      X.4.5   Mail system congestion

         The mail system was unable to deliver the message because the
         mail system was congested.  This is useful only as a persistent
         transient error.





Vaudreuil                   Standards Track                     [Page 9]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.4.6   Routing loop detected

         A routing loop caused the message to be forwarded too many
         times, either because of incorrect routing tables or a user-
         forwarding loop.  This is useful only as a persistent transient
         error.

      X.4.7   Delivery time expired

         The message was considered too old by the rejecting system,
         either because it remained on that host too long or because the
         time-to-live value specified by the sender of the message was
         exceeded.  If possible, the code for the actual problem found
         when delivery was attempted should be returned rather than this
         code.

3.6 Mail Delivery Protocol Status

      X.5.0   Other or undefined protocol status

         Something was wrong with the protocol necessary to deliver the
         message to the next hop and the problem cannot be well
         expressed with any of the other provided detail codes.

      X.5.1   Invalid command

         A mail transaction protocol command was issued which was either
         out of sequence or unsupported.  This is useful only as a
         permanent error.

      X.5.2   Syntax error

         A mail transaction protocol command was issued which could not
         be interpreted, either because the syntax was wrong or the
         command is unrecognized.  This is useful only as a permanent
         error.

      X.5.3   Too many recipients

         More recipients were specified for the message than could have
         been delivered by the protocol.  This error should normally
         result in the segmentation of the message into two, the
         remainder of the recipients to be delivered on a subsequent
         delivery attempt.  It is included in this list in the event
         that such segmentation is not possible.






Vaudreuil                   Standards Track                    [Page 10]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.5.4   Invalid command arguments

         A valid mail transaction protocol command was issued with
         invalid arguments, either because the arguments were out of
         range or represented unrecognized features.  This is useful
         only as a permanent error.

      X.5.5   Wrong protocol version

         A protocol version mis-match existed which could not be
         automatically resolved by the communicating parties.

3.7 Message Content or Message Media Status

      X.6.0   Other or undefined media error

         Something about the content of a message caused it to be
         considered undeliverable and the problem cannot be well
         expressed with any of the other provided detail codes.

      X.6.1   Media not supported

         The media of the message is not supported by either the
         delivery protocol or the next system in the forwarding path.
         This is useful only as a permanent error.

      X.6.2   Conversion required and prohibited

         The content of the message must be converted before it can be
         delivered and such conversion is not permitted.  Such
         prohibitions may be the expression of the sender in the message
         itself or the policy of the sending host.

      X.6.3   Conversion required but not supported

         The message content must be converted in order to be forwarded
         but such conversion is not possible or is not practical by a
         host in the forwarding path.  This condition may result when an
         ESMTP gateway supports 8bit transport but is not able to
         downgrade the message to 7 bit as required for the next hop.

      X.6.4   Conversion with loss performed

         This is a warning sent to the sender when message delivery was
         successfully but when the delivery required a conversion in
         which some data was lost.  This may also be a permanent error
         if the sender has indicated that conversion with loss is
         prohibited for the message.



Vaudreuil                   Standards Track                    [Page 11]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.6.5   Conversion Failed

         A conversion was required but was unsuccessful.  This may be
         useful as a permanent or persistent temporary notification.

3.8 Security or Policy Status

      X.7.0   Other or undefined security status

         Something related to security caused the message to be
         returned, and the problem cannot be well expressed with any of
         the other provided detail codes.  This status code may also be
         used when the condition cannot be further described because of
         security policies in force.

      X.7.1   Delivery not authorized, message refused

         The sender is not authorized to send to the destination.  This
         can be the result of per-host or per-recipient filtering.  This
         memo does not discuss the merits of any such filtering, but
         provides a mechanism to report such.  This is useful only as a
         permanent error.

      X.7.2   Mailing list expansion prohibited

         The sender is not authorized to send a message to the intended
         mailing list.  This is useful only as a permanent error.

      X.7.3   Security conversion required but not possible

         A conversion from one secure messaging protocol to another was
         required for delivery and such conversion was not possible.
         This is useful only as a permanent error.

      X.7.4   Security features not supported

         A message contained security features such as secure
         authentication that could not be supported on the delivery
         protocol.  This is useful only as a permanent error.

      X.7.5   Cryptographic failure

         A transport system otherwise authorized to validate or decrypt
         a message in transport was unable to do so because necessary
         information such as key was not available or such information
         was invalid.





Vaudreuil                   Standards Track                    [Page 12]

RFC 3463           Enhanced Mail System Status Codes        January 2003


      X.7.6   Cryptographic algorithm not supported

         A transport system otherwise authorized to validate or decrypt
         a message was unable to do so because the necessary algorithm
         was not supported.

      X.7.7   Message integrity failure

         A transport system otherwise authorized to validate a message
         was unable to do so because the message was corrupted or
         altered.  This may be useful as a permanent, transient
         persistent, or successful delivery code.

4. Normative References

   [RFC2119] Bradner, S., "Key words for use in RFCs to Indicate
             Requirement Levels", BCP 14, RFC 2119, March 1997.

   [SMTP]    Postel, J., "Simple Mail Transfer Protocol", STD 10, RFC
             821, August 1982.

   [DSN]     Moore, K. and G. Vaudreuil, "An Extensible Message Format
             for Delivery Status Notifications", RFC 3464, January 2003.

5. Security Considerations

   This document describes a status code system with increased
   precision.  Use of these status codes may disclose additional
   information about how an internal mail system is implemented beyond
   that currently available.





















Vaudreuil                   Standards Track                    [Page 13]

RFC 3463           Enhanced Mail System Status Codes        January 2003


Appendix A - Collected Status Codes

         X.1.0     Other address status
         X.1.1     Bad destination mailbox address
         X.1.2     Bad destination system address
         X.1.3     Bad destination mailbox address syntax
         X.1.4     Destination mailbox address ambiguous
         X.1.5     Destination mailbox address valid
         X.1.6     Mailbox has moved
         X.1.7     Bad sender's mailbox address syntax
         X.1.8     Bad sender's system address

         X.2.0     Other or undefined mailbox status
         X.2.1     Mailbox disabled, not accepting messages
         X.2.2     Mailbox full
         X.2.3     Message length exceeds administrative limit.
         X.2.4     Mailing list expansion problem

         X.3.0     Other or undefined mail system status
         X.3.1     Mail system full
         X.3.2     System not accepting network messages
         X.3.3     System not capable of selected features
         X.3.4     Message too big for system

         X.4.0     Other or undefined network or routing status
         X.4.1     No answer from host
         X.4.2     Bad connection
         X.4.3     Routing server failure
         X.4.4     Unable to route
         X.4.5     Network congestion
         X.4.6     Routing loop detected
         X.4.7     Delivery time expired

         X.5.0     Other or undefined protocol status
         X.5.1     Invalid command
         X.5.2     Syntax error
         X.5.3     Too many recipients
         X.5.4     Invalid command arguments
         X.5.5     Wrong protocol version

         X.6.0     Other or undefined media error
         X.6.1     Media not supported
         X.6.2     Conversion required and prohibited
         X.6.3     Conversion required but not supported
         X.6.4     Conversion with loss performed
         X.6.5     Conversion failed





Vaudreuil                   Standards Track                    [Page 14]

RFC 3463           Enhanced Mail System Status Codes        January 2003


         X.7.0     Other or undefined security status
         X.7.1     Delivery not authorized, message refused
         X.7.2     Mailing list expansion prohibited
         X.7.3     Security conversion required but not possible
         X.7.4     Security features not supported
         X.7.5     Cryptographic failure
         X.7.6     Cryptographic algorithm not supported
         X.7.7     Message integrity failure

Appendix B - Changes from RFC1893

   Changed Authors contact information.

   Updated required standards boilerplate.

   Edited the text to make it spell-checker and grammar checker
   compliant.

   Modified the text describing the persistent transient failure to more
   closely reflect current practice and understanding.

   Eliminated the restriction on the X.4.7 codes limiting them to
   persistent transient errors.

Author's Address

   Gregory M. Vaudreuil
   Lucent Technologies
   7291 Williamson Rd
   Dallas, Tx. 75214

   Phone: +1 214 823 9325
   EMail: GregV@ieee.org


















Vaudreuil                   Standards Track                    [Page 15]

RFC 3463           Enhanced Mail System Status Codes        January 2003


Full Copyright Statement

   Copyright (C) The Internet Society (2003).  All Rights Reserved.

   This document and translations of it may be copied and furnished to
   others, and derivative works that comment on or otherwise explain it
   or assist in its implementation may be prepared, copied, published
   and distributed, in whole or in part, without restriction of any
   kind, provided that the above copyright notice and this paragraph are
   included on all such copies and derivative works.  However, this
   document itself may not be modified in any way, such as by removing
   the copyright notice or references to the Internet Society or other
   Internet organizations, except as needed for the purpose of
   developing Internet standards in which case the procedures for
   copyrights defined in the Internet Standards process must be
   followed, or as required to translate it into languages other than
   English.

   The limited permissions granted above are perpetual and will not be
   revoked by the Internet Society or its successors or assigns.

   This document and the information contained herein is provided on an
   "AS IS" basis and THE INTERNET SOCIETY AND THE INTERNET ENGINEERING
   TASK FORCE DISCLAIMS ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING
   BUT NOT LIMITED TO ANY WARRANTY THAT THE USE OF THE INFORMATION
   HEREIN WILL NOT INFRINGE ANY RIGHTS OR ANY IMPLIED WARRANTIES OF
   MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.

Acknowledgement

   Funding for the RFC Editor function is currently provided by the
   Internet Society.



















Vaudreuil                   Standards Track                    [Page 16]

