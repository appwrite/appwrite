





Network Working Group                                       N. Freed
Request for Comments: RFC 2034                              Innosoft
Category: Standards Track                               October 1996


                       SMTP Service Extension for
                     Returning Enhanced Error Codes

Status of this Memo

   This document specifies an Internet standards track protocol for the
   Internet community, and requests discussion and suggestions for
   improvements.  Please refer to the current edition of the "Internet
   Official Protocol Standards" (STD 1) for the standardization state
   and status of this protocol.  Distribution of this memo is unlimited.

1.  Abstract

   This memo defines an extension to the SMTP service [RFC-821, RFC-
   1869] whereby an SMTP server augments its responses with the enhanced
   mail system status codes defined in RFC 1893.  These codes can then
   be used to provide more informative explanations of error conditions,
   especially in the context of the delivery status notifications format
   defined in RFC 1894.

2.  Introduction

   Although SMTP is widely and robustly deployed, various extensions
   have been requested by parts of the Internet community. In
   particular, in the modern, international, and multilingual Internet a
   need exists to assign codes to specific error conditions that can be
   translated into different languages. RFC 1893 defines such a set of
   status codes and RFC 1894 defines a mechanism to send such coded
   material to users. However, in many cases the agent creating the RFC
   1894 delivery status notification is doing so in response to errors
   it received from a remote SMTP server.

   As such, remote servers need a mechanism for embedding enhanced
   status codes in their responses as well as a way to indicate to a
   client when they are in fact doing this. This memo uses the SMTP
   extension mechanism described in RFC 1869 to define such a mechanism.










Freed                       Standards Track                     [Page 1]

RFC 2034               SMTP Enhanced Error Codes            October 1996


3.  Framework for the Enhanced Error Statuses Extension

   The enhanced error statuses transport extension is laid out as
   follows:

   (1)   the name of the SMTP service extension defined here is
         Enhanced-Status-Codes;

   (2)   the EHLO keyword value associated with the extension is
         ENHANCEDSTATUSCODES;

   (3)   no parameter is used with the ENHANCEDSTATUSCODES EHLO
         keyword;

   (4)   the text part of all 2xx, 4xx, and 5xx SMTP responses
         other than the initial greeting and any response to
         HELO or EHLO are prefaced with a status code as defined
         in RFC 1893. This status code is always followed by one
         or more spaces.

   (5)   no additional SMTP verbs are defined by this extension;
         and,

   (6)   the next section specifies how support for the
         extension affects the behavior of a server and client
         SMTP.

4.  The Enhanced-Status-Codes service extension

   Servers supporting the Enhanced-Status-Codes extension must preface
   the text part of almost all response lines with a status code. As in
   RFC 1893, the syntax of these status codes is given by the ABNF:

        status-code ::= class "." subject "." detail
        class       ::= "2" / "4" / "5"
        subject     ::= 1*3digit
        detail      ::= 1*3digit

   These codes must appear in all 2xx, 4xx, and 5xx response lines other
   than initial greeting and any response to HELO or EHLO. Note that 3xx
   responses are NOT included in this list.

   All status codes returned by the server must agree with the primary
   response code, that is, a 2xx response must incorporate a 2.X.X code,
   a 4xx response must incorporate a 4.X.X code, and a 5xx response must
   incorporate a 5.X.X code.





Freed                       Standards Track                     [Page 2]

RFC 2034               SMTP Enhanced Error Codes            October 1996


   When responses are continued across multiple lines the same status
   code must appear at the beginning of the text in each line of the
   response.

   Servers supporting this extension must attach enhanced status codes
   to their responses regardless of whether or not EHLO is employed by
   the client.

5.  Status Codes and Negotiation

   This specification does not provide a means for clients to request
   that status codes be returned or that they not be returned; a
   compliant server includes these codes in the responses it sends
   regardless of whether or not the client expects them.  This is
   somewhat different from most other SMTP extensions, where generally
   speaking a client must specifically make a request before the
   extended server behaves any differently than an unextended server.
   The omission of client negotiation in this case is entirely
   intentional: Given the generally poor state of SMTP server error code
   implementation it is felt that any step taken towards more
   comprehensible error codes is something that all clients, extended or
   not, should benefit from.

   IMPORTANT NOTE:  The use of this approach in this extension should be
   seen as a very special case.  It MUST NOT be taken as a license for
   future SMTP extensions to dramatically change the nature of SMTP
   client-server interaction without proper announcement from the server
   and a corresponding enabling command from the client.

6.  Usage Example

   The following dialogue illustrates the use of enhanced status codes
   by a server:

   S: <wait for connection on TCP port 25>
   C: <open connection to server>
   S: 220 dbc.mtview.ca.us SMTP service ready
   C: EHLO ymir.claremont.edu
   S: 250-dbc.mtview.ca.us says hello
   S: 250 ENHANCEDSTATUSCODES
   C: MAIL FROM:<ned@ymir.claremont.edu>
   S: 250 2.1.0 Originator <ned@ymir.claremont.edu> ok
   C: RCPT TO:<mrose@dbc.mtview.ca.us>
   S: 250 2.1.5 Recipient <mrose@dbc.mtview.ca.us> ok
   C: RCPT TO:<nosuchuser@dbc.mtview.ca.us>
   S: 550 5.1.1 Mailbox "nosuchuser" does not exist
   C: RCPT TO:<remoteuser@isi.edu>
   S: 551-5.7.1 Forwarding to remote hosts disabled



Freed                       Standards Track                     [Page 3]

RFC 2034               SMTP Enhanced Error Codes            October 1996


   S: 551 5.7.1 Select another host to act as your forwarder
   C: DATA
   S: 354 Send message, ending in CRLF.CRLF.
    ...
   C: .
   S: 250 2.6.0 Message accepted
   C: QUIT
   S: 221 2.0.0 Goodbye

   The client that receives these responses might then send a
   nondelivery notification of the general form:

      Date: Mon, 11 Mar 1996 09:21:47 -0400
      From: Mail Delivery Subsystem <mailer-daemon@ymir.claremont.edu>
      Subject: Returned mail
      To: <ned@ymir.claremont.edu>
      MIME-Version: 1.0
      Content-Type: multipart/report; report-type=delivery-status;
            boundary="JAA13167.773673707/YMIR.CLAREMONT.EDU"

      --JAA13167.773673707/YMIR.CLAREMONT.EDU
      content-type: text/plain; charset=us-ascii

         ----- Mail was successfully relayed to
               the following addresses -----

      <mrose@dbc.mtview.ca.us>

         ----- The following addresses had delivery problems -----
      <nosuchuser@dbc.mtview.ca.us>
        (Mailbox "nosuchuser" does not exist)
      <remoteuser@isi.edu>
        (Forwarding to remote hosts disabled)

      --JAA13167.773673707/YMIR.CLAREMONT.EDU
      content-type: message/delivery-status

      Reporting-MTA: dns; ymir.claremont.edu

      Original-Recipient: rfc822;mrose@dbc.mtview.ca.us
      Final-Recipient: rfc822;mrose@dbc.mtview.ca.us
      Action: relayed
      Status: 2.1.5 (Destination address valid)
      Diagnostic-Code: smtp;
       250 Recipient <mrose@dbc.mtview.ca.us> ok
      Remote-MTA: dns; dbc.mtview.ca.us





Freed                       Standards Track                     [Page 4]

RFC 2034               SMTP Enhanced Error Codes            October 1996


      Original-Recipient: rfc822;nosuchuser@dbc.mtview.ca.us
      Final-Recipient: rfc822;nosuchuser@dbc.mtview.ca.us
      Action: failed
      Status: 5.1.1 (Bad destination mailbox address)
      Diagnostic-Code: smtp;
       550 Mailbox "nosuchuser" does not exist
      Remote-MTA: dns; dbc.mtview.ca.us

      Original-Recipient: rfc822;remoteuser@isi.edu
      Final-Recipient: rfc822;remoteuser@isi.edu
      Action: failed
      Status: 5.7.1 (Delivery not authorized, message refused)
      Diagnostic-Code: smtp;
        551 Forwarding to remote hosts disabled
        Select another host to act as your forwarder
      Remote-MTA: dns; dbc.mtview.ca.us

      --JAA13167.773673707/YMIR.CLAREMONT.EDU
      content-type: message/rfc822

      [original message goes here]
      --JAA13167.773673707/YMIR.CLAREMONT.EDU--

   Note that in order to reduce clutter the reporting MTA has omitted
   enhanced status code information from the diagnostic-code fields it
   has generated.

7.  Security Considerations

   Additional detail in server responses axiomatically provides
   additional information about the server.  It is conceivable that
   additional information of this sort may be of assistance in
   circumventing server security.  The advantages of provides additional
   information must always be weighed against the security implications
   of doing so.
















Freed                       Standards Track                     [Page 5]

RFC 2034               SMTP Enhanced Error Codes            October 1996


8.  References

   [RFC-821]
        Postel, J., "Simple Mail Transfer Protocol", RFC 821,
        August, 1982.  (August, 1982).

   [RFC-1869]
        Rose, M., Stefferud, E., Crocker, C., Klensin, J., Freed,
        N., "SMTP Service Extensions", RFC 1869, November, 1995.

   [RFC-1893]
        Vaudreuil, G., "Enhanced Mail System Status Codes", RFC
        1893, January, 1996.

   [RFC-1894]
        Moore, K., Vaudreuil, G., "An Extensible Message Format
        for Delivery Status Notifications", RFC 1894, January,
        1996.

9.  Author Address

   Ned Freed
   Innosoft International, Inc.
   1050 East Garvey Avenue South
   West Covina, CA 91790
   USA
    tel: +1 818 919 3600           fax: +1 818 919 3614
    email: ned@innosoft.com























Freed                       Standards Track                     [Page 6]

