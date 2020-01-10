{if !$submitPayment}
    <p>Creating a PayPal Billing Agreement will allow us to automatically charge your PayPal account for one-time and recurring invoices.</p>
    <p><strong>All invoices set to use PayPal Billing Agreements will be automatically charged 3 days in advance of the due date.</strong></p>

    <hr />
{/if}

<div style="text-align: center;">
    {if $submitPayment}
        <h3>Submit Payment for Invoice #{$invoiceid}</h3>
        <br />
        <p><strong>Total Payment Amount:</strong> ${$balance}</p>
        <p><strong>Billing Agreement ID:</strong> {$agreement->id}</p>

        <br />

        <form method="post" action="{$systemUrl}/paypalbilling.php?action=submitpayment&invoiceid={$invoiceid}" id="submitpayment">
            <input type="hidden" name="confirm" value="true">

            <input type="submit" class="btn btn-default" id="submitpaymentbutton" value="Submit Payment">
            <a href="{$systemUrl}/viewinvoice.php?id={$invoiceid}&paymentfailed=true" class="btn btn-default">Cancel</a>
        </form>

        <script type="text/javascript">
            $('#submitpayment').submit(function(e) {
                e.preventDefault();

                var button = $('#submitpaymentbutton');

                button.attr('disabled', 'disabled');
                button.attr('value', 'Loading ...');

                $.ajax({
                    type: 'POST',
                    url: '{$systemUrl}/paypalbilling.php?action=submitpayment&invoiceid={$invoiceid}',
                    data: {
                        noredirect: true,
                        confirm: true
                    }
                }).done(function () {
                    window.location = '{$systemUrl}/viewinvoice.php?id={$invoiceid}&paymentsuccess=true';
                }).error(function () {
                    window.location = '{$systemUrl}/viewinvoice.php?id={$invoiceid}&paymentfailed=true';
                });
            });
        </script>
    {else}
        {if $billingAgreement}
            <p>You currently have an active billing agreement.</p>
            <p><strong>Billing Agreement ID:</strong> {$billingAgreement->id}</p>
            <p><strong>Created On:</strong> {$billingAgreementDate}</p>

            <br />

            <form method="post" action="{$systemUrl}/paypalbilling.php?action=cancel" id="cancelbillingagreement">
                <input type="submit" class="btn btn-default" value="Cancel Billing Agreement" id="cancelbillingagreementbutton" />
            </form>

            <script type="text/javascript">
                $('#cancelbillingagreement').submit(function(e) {
                    e.preventDefault();

                    var retval = confirm('Are you sure you want to cancel your current PayPal Billing Agreement?');

                    if (retval === false) {
                        return;
                    }

                    var button = $('#cancelbillingagreementbutton');

                    button.attr('disabled', 'disabled');
                    button.attr('value', 'Loading ...');

                    $.ajax({
                        type: 'POST',
                        url: '{$systemUrl}/paypalbilling.php?action=cancel',
                        data: {
                            noredirect: true,
                        }
                    }).done(function () {
                        window.location = '{$systemUrl}/paypalbilling.php?action=manage';
                    }).error(function () {
                        alert('Unable to cancel PayPal billing agreement. Please refresh the page and try again later or cancel this agreement directly on paypal.com.');

                        button.attr('disabled', null);
                        button.attr('value', 'Cancel Billing Agreement');
                    });
                });
            </script>
        {else}
            {if $action eq 'returnfailed'}
                <div class="alert alert-danger" role="alert">
                    We were unable to process your PayPal Billing Agreement. Please click the link below to try again.
                </div>
            {/if}
            <p>You currently do not have an active billing agreement.</p>

            {if $invoiceid}
                <p><strong>You must create a new PayPal billing agreement before proceeding with payment.</strong></p>
            {/if}

            <br />

            <form method="post" action="{$systemUrl}/paypalbilling.php?action=create" id="createbillingagreement">
                {if $invoiceid}
                    <input type="hidden" name="invoiceid" value="{$invoiceid}" />
                {/if}
                <input type="submit" class="btn btn-default" value="Create New Agreement" id="createbillingagreementbutton" />
            </form>

            <script type="text/javascript">
                $('#createbillingagreement').submit(function(e) {
                    e.preventDefault();

                    var button = $('#createbillingagreementbutton');

                    button.attr('disabled', 'disabled');
                    button.attr('value', 'Loading ...');

                    $.ajax({
                        type: 'POST',
                        url: '{$systemUrl}/paypalbilling.php?action=create',
                        data: {
                            noredirect: true,
                            {if $invoiceid}invoiceid: {$invoiceid}{/if}
                        }
                    }).done(function (token) {
                        button.attr('value', 'Redirecting to PayPal...');
                        window.location = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=' + token;
                    }).error(function () {
                        alert('Unable to create new Billing Agreement. Please try again.');

                        button.attr('disabled', null);
                        button.attr('value', 'Create New Agreement');
                    });
                });
            </script>
        {/if}
    {/if}
</div>

<br />
<br />